<?php declare(strict_types=1);

namespace Salient\Sync\Concept;

use Lkrms\Iterator\Contract\FluentIteratorInterface;
use Lkrms\Iterator\IterableIterator;
use Salient\Core\Catalog\ArrayMapperFlag;
use Salient\Core\Catalog\ListConformity;
use Salient\Core\Concern\HasChainableMethods;
use Salient\Core\Concern\HasReadableProperties;
use Salient\Core\Contract\Chainable;
use Salient\Core\Contract\PipelineInterface;
use Salient\Core\Contract\Readable;
use Salient\Core\Pipeline;
use Salient\Sync\Catalog\FilterPolicy;
use Salient\Sync\Catalog\SyncEntitySource;
use Salient\Sync\Catalog\SyncOperation as OP;
use Salient\Sync\Catalog\SyncOperations;
use Salient\Sync\Contract\ISyncContext;
use Salient\Sync\Contract\ISyncDefinition;
use Salient\Sync\Contract\ISyncEntity;
use Salient\Sync\Contract\ISyncProvider;
use Salient\Sync\Exception\SyncEntityNotFoundException;
use Salient\Sync\Exception\SyncFilterPolicyViolationException;
use Salient\Sync\Support\SyncIntrospector;
use Closure;
use LogicException;

/**
 * Provides direct access to an ISyncProvider's implementation of sync
 * operations for an entity
 *
 * @template TEntity of ISyncEntity
 * @template TProvider of ISyncProvider
 *
 * @property-read class-string<TEntity> $Entity The ISyncEntity being serviced
 * @property-read TProvider $Provider The ISyncProvider servicing the entity
 * @property-read array<OP::*> $Operations A list of supported sync operations
 * @property-read ListConformity::* $Conformity The conformity level of data returned by the provider for this entity
 * @property-read FilterPolicy::* $FilterPolicy The action to take when filters are unclaimed by the provider
 * @property-read array<OP::*,Closure(ISyncDefinition<TEntity,TProvider>, OP::*, ISyncContext, mixed...): (iterable<TEntity>|TEntity)> $Overrides An array that maps sync operations to closures that override other implementations
 * @property-read array<array-key,array-key|array-key[]>|null $KeyMap An array that maps provider (backend) keys to one or more entity keys
 * @property-read int-mask-of<ArrayMapperFlag::*> $KeyMapFlags Passed to the array mapper if `$keyMap` is provided
 * @property-read PipelineInterface<mixed[],TEntity,array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null $PipelineFromBackend A pipeline that maps data from the provider to entity-compatible associative arrays, or `null` if mapping is not required
 * @property-read PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null $PipelineToBackend A pipeline that maps serialized entities to data compatible with the provider, or `null` if mapping is not required
 * @property-read bool $ReadFromReadList If true, perform READ operations by iterating over entities returned by READ_LIST
 * @property-read SyncEntitySource::*|null $ReturnEntitiesFrom Where to acquire entity data for the return value of a successful CREATE, UPDATE or DELETE operation
 *
 * @implements ISyncDefinition<TEntity,TProvider>
 */
abstract class SyncDefinition implements ISyncDefinition, Chainable, Readable
{
    use HasChainableMethods;
    use HasReadableProperties;

    /**
     * Return a closure to perform a sync operation on the entity
     *
     * This method is called if `$operation` is found in
     * {@see SyncDefinition::$Operations}.
     *
     * @param OP::* $operation
     * @return (Closure(ISyncContext, mixed...): (iterable<TEntity>|TEntity))|null
     * @phpstan-return (
     *     $operation is OP::READ
     *     ? (Closure(ISyncContext, int|string|null, mixed...): TEntity)
     *     : (
     *         $operation is OP::READ_LIST
     *         ? (Closure(ISyncContext, mixed...): iterable<TEntity>)
     *         : (
     *             $operation is OP::CREATE|OP::UPDATE|OP::DELETE
     *             ? (Closure(ISyncContext, TEntity, mixed...): TEntity)
     *             : (Closure(ISyncContext, iterable<TEntity>, mixed...): iterable<TEntity>)
     *         )
     *     )
     * )|null
     */
    abstract protected function getClosure($operation): ?Closure;

    /**
     * The ISyncEntity being serviced
     *
     * @var class-string<TEntity>
     */
    protected $Entity;

    /**
     * The ISyncProvider servicing the entity
     *
     * @var TProvider
     */
    protected $Provider;

    /**
     * A list of supported sync operations
     *
     * @var array<OP::*>
     */
    protected $Operations;

    /**
     * The conformity level of data returned by the provider for this entity
     *
     * Use {@see ListConformity::COMPLETE} or {@see ListConformity::PARTIAL}
     * wherever possible to improve performance.
     *
     * @var ListConformity::*
     */
    protected $Conformity;

    /**
     * The action to take when filters are unclaimed by the provider
     *
     * To prevent a request for entities that meet one or more criteria
     * inadvertently reaching the backend as a request for a larger set of
     * entities--if not all of them--the default policy if there are unclaimed
     * filters is {@see FilterPolicy::THROW_EXCEPTION}. See {@see FilterPolicy}
     * for alternative policies and {@see ISyncContext::withArgs()} for more
     * information about filters.
     *
     * @var FilterPolicy::*
     */
    protected $FilterPolicy;

    /**
     * An array that maps sync operations to closures that override other
     * implementations
     *
     * Two arguments are inserted before the operation's arguments:
     *
     * - The sync definition object
     * - The sync operation
     *
     * Operations implemented here don't need to be added to
     * {@see SyncDefinition::$Operations}.
     *
     * @var array<OP::*,Closure(ISyncDefinition<TEntity,TProvider>, OP::*, ISyncContext, mixed...): (iterable<TEntity>|TEntity)>
     */
    protected $Overrides = [];

    /**
     * An array that maps provider (backend) keys to one or more entity keys
     *
     * Providing `$keyMap` has the same effect as passing the following pipeline
     * to `$pipelineFromBackend`:
     *
     * ```php
     * <?php
     * Pipeline::create()->throughKeyMap($keyMap);
     * ```
     *
     * @var array<array-key,array-key|array-key[]>|null
     */
    protected $KeyMap;

    /**
     * Passed to the array mapper if `$keyMap` is provided
     *
     * @var int-mask-of<ArrayMapperFlag::*>
     */
    protected $KeyMapFlags;

    /**
     * A pipeline that maps data from the provider to entity-compatible
     * associative arrays, or `null` if mapping is not required
     *
     * @var PipelineInterface<mixed[],TEntity,array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null
     */
    protected $PipelineFromBackend;

    /**
     * A pipeline that maps serialized entities to data compatible with the
     * provider, or `null` if mapping is not required
     *
     * @var PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null
     */
    protected $PipelineToBackend;

    /**
     * If true, perform READ operations by iterating over entities returned by
     * READ_LIST
     *
     * Useful with backends that don't provide an endpoint for retrieval of
     * individual entities.
     *
     * @var bool
     */
    protected $ReadFromReadList;

    /**
     * Where to acquire entity data for the return value of a successful CREATE,
     * UPDATE or DELETE operation
     *
     * @var SyncEntitySource::*|null
     */
    protected $ReturnEntitiesFrom;

    /**
     * @internal
     *
     * @var SyncIntrospector<TEntity>
     */
    protected $EntityIntrospector;

    /**
     * @internal
     *
     * @var SyncIntrospector<TProvider>
     */
    protected $ProviderIntrospector;

    /**
     * @var array<OP::*,Closure>
     */
    private $Closures = [];

    /**
     * @var static|null
     */
    private $WithoutOverrides;

    /**
     * @param class-string<TEntity> $entity
     * @param TProvider $provider
     * @param array<OP::*> $operations
     * @param ListConformity::* $conformity
     * @param FilterPolicy::*|null $filterPolicy
     * @param array<int-mask-of<OP::*>,Closure(ISyncDefinition<TEntity,TProvider>, OP::*, ISyncContext, mixed...): (iterable<TEntity>|TEntity)> $overrides
     * @param array<array-key,array-key|array-key[]>|null $keyMap
     * @param int-mask-of<ArrayMapperFlag::*> $keyMapFlags
     * @param PipelineInterface<mixed[],TEntity,array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null $pipelineFromBackend
     * @param PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>|null $pipelineToBackend
     * @param SyncEntitySource::*|null $returnEntitiesFrom
     */
    public function __construct(
        string $entity,
        ISyncProvider $provider,
        array $operations = [],
        $conformity = ListConformity::NONE,
        ?int $filterPolicy = null,
        array $overrides = [],
        ?array $keyMap = null,
        int $keyMapFlags = ArrayMapperFlag::ADD_UNMAPPED,
        ?PipelineInterface $pipelineFromBackend = null,
        ?PipelineInterface $pipelineToBackend = null,
        bool $readFromReadList = false,
        ?int $returnEntitiesFrom = null
    ) {
        if ($filterPolicy === null) {
            $filterPolicy = $provider->getFilterPolicy();
            if ($filterPolicy === null) {
                $filterPolicy = FilterPolicy::THROW_EXCEPTION;
            }
        }

        $this->Entity = $entity;
        $this->Provider = $provider;
        $this->Conformity = $conformity;
        $this->FilterPolicy = $filterPolicy;
        $this->KeyMap = $keyMap;
        $this->KeyMapFlags = $keyMapFlags;
        $this->PipelineFromBackend = $pipelineFromBackend;
        $this->PipelineToBackend = $pipelineToBackend;
        $this->ReadFromReadList = $readFromReadList;
        $this->ReturnEntitiesFrom = $returnEntitiesFrom;

        // Expand $overrides into an entry per operation
        foreach ($overrides as $ops => $override) {
            foreach (SyncOperations::ALL as $op) {
                if (!($ops & $op)) {
                    continue;
                }
                if (array_key_exists($op, $this->Overrides)) {
                    throw new LogicException(sprintf(
                        'Too many overrides for SyncOperation::%s on %s: %s',
                        OP::toName($op),
                        $entity,
                        get_class($provider),
                    ));
                }
                $this->Overrides[$op] = $override;
            }
        }

        // Combine overridden operations with $operations and discard any
        // invalid values
        $this->Operations = array_intersect(
            SyncOperations::ALL,
            array_merge(array_values($operations), array_keys($this->Overrides))
        );

        $this->EntityIntrospector = SyncIntrospector::get($entity);
        $this->ProviderIntrospector = SyncIntrospector::get(get_class($provider));
    }

    public function __clone()
    {
        $this->Closures = [];
        $this->WithoutOverrides = null;
    }

    /**
     * @inheritDoc
     */
    final public function getSyncOperationClosure($operation): ?Closure
    {
        // Return a previous result if possible
        if (array_key_exists($operation, $this->Closures)) {
            return $this->Closures[$operation];
        }

        // Overrides take precedence over everything else, including declared
        // methods
        if (array_key_exists($operation, $this->Overrides)) {
            return $this->Closures[$operation] =
                fn(ISyncContext $ctx, ...$args) =>
                    $this->Overrides[$operation](
                        $this,
                        $operation,
                        $this->getContextWithFilterCallback($operation, $ctx),
                        ...$args
                    );
        }

        // If a method has been declared for this operation, use it, even if
        // it's not in $this->Operations
        $closure = $this->ProviderIntrospector->getDeclaredSyncOperationClosure(
            $operation,
            $this->EntityIntrospector,
            $this->Provider
        );

        if ($closure) {
            return $this->Closures[$operation] =
                fn(ISyncContext $ctx, ...$args) =>
                    $closure(
                        $this->getContextWithFilterCallback($operation, $ctx),
                        ...$args
                    );
        }

        if ($operation === OP::READ &&
                $this->ReadFromReadList &&
                ($closure = $this->getSyncOperationClosure(OP::READ_LIST))) {
            return $this->Closures[$operation] =
                function (ISyncContext $ctx, $id, ...$args) use ($closure) {
                    $entity = $this
                        ->getFluentIterator($closure($ctx, ...$args))
                        ->nextWithValue('Id', $id);
                    if ($entity === null) {
                        throw new SyncEntityNotFoundException($this->Provider, $this->Entity, $id);
                    }
                    return $entity;
                };
        }

        // Return null if the operation doesn't appear in $this->Operations
        if (!in_array($operation, $this->Operations, true)) {
            return $this->Closures[$operation] = null;
        }

        // Otherwise, request a closure from the subclass
        return $this->Closures[$operation] = $this->getClosure($operation);
    }

    /**
     * Ignoring defined overrides, get a closure that uses the provider to
     * perform a sync operation on the entity
     *
     * Useful within overrides when a fallback implementation is required.
     *
     * @param OP::* $operation
     * @return (Closure(ISyncContext, mixed...): (iterable<TEntity>|TEntity))|null
     * @phpstan-return (
     *     $operation is OP::READ
     *     ? (Closure(ISyncContext, int|string|null, mixed...): TEntity)
     *     : (
     *         $operation is OP::READ_LIST
     *         ? (Closure(ISyncContext, mixed...): iterable<TEntity>)
     *         : (
     *             $operation is OP::CREATE|OP::UPDATE|OP::DELETE
     *             ? (Closure(ISyncContext, TEntity, mixed...): TEntity)
     *             : (Closure(ISyncContext, iterable<TEntity>, mixed...): iterable<TEntity>)
     *         )
     *     )
     * )|null
     *
     * @see SyncDefinition::$Overrides
     */
    final public function getFallbackClosure($operation): ?Closure
    {
        $clone = $this->WithoutOverrides;
        if (!$clone) {
            $clone = clone $this;
            $clone->Overrides = [];
            $this->WithoutOverrides = $clone;
        }

        return $clone->getSyncOperationClosure($operation);
    }

    /**
     * Specify whether to perform READ operations by iterating over entities
     * returned by READ_LIST
     *
     * @return $this
     */
    final public function withReadFromReadList(bool $readFromReadList = true)
    {
        $clone = clone $this;
        $clone->ReadFromReadList = $readFromReadList;

        return $clone;
    }

    /**
     * Get an entity-to-data pipeline for the entity
     *
     * Before returning the pipeline:
     *
     * - a pipe that serializes any unserialized {@see ISyncEntity} instances is
     *   added via {@see PipelineInterface::through()}
     *
     * @return PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>
     */
    final protected function getPipelineToBackend(): PipelineInterface
    {
        /** @var PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}> */
        $pipeline = $this->PipelineToBackend ?? Pipeline::create();

        /** @var PipelineInterface<TEntity,mixed[],array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}> */
        $pipeline = $pipeline->through(
            fn($payload, Closure $next) =>
                $payload instanceof ISyncEntity
                    ? $next($payload->toArray())
                    : $next($payload)
        );

        return $pipeline;
    }

    /**
     * Get a data-to-entity pipeline for the entity
     *
     * Before returning the pipeline:
     *
     * - if the definition has a key map, it is applied via
     *   {@see PipelineInterface::throughKeyMap()}
     * - a closure to create instances of the entity from arrays returned by the
     *   pipeline is applied via {@see PipelineInterface::then()}
     *
     * @return PipelineInterface<mixed[],TEntity,array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}>
     */
    final protected function getPipelineFromBackend(): PipelineInterface
    {
        /** @var PipelineInterface<mixed[],TEntity,array{0:OP::*,1:ISyncContext,2?:int|string|TEntity|TEntity[]|null,...}> */
        $pipeline = $this->PipelineFromBackend ?? Pipeline::create();

        if ($this->KeyMap !== null) {
            $pipeline = $pipeline->throughKeyMap($this->KeyMap, $this->KeyMapFlags);
        }

        return $pipeline
            ->then(
                function (array $data, PipelineInterface $pipeline, $arg) use (&$ctx, &$closure) {
                    if (!$ctx) {
                        /** @var ISyncContext $ctx */
                        [, $ctx] = $arg;
                        $ctx = $ctx->withConformity($this->Conformity);
                    }
                    if (!$closure) {
                        $closure = in_array(
                            $this->Conformity,
                            [ListConformity::PARTIAL, ListConformity::COMPLETE]
                        )
                            ? SyncIntrospector::getService($ctx->container(), $this->Entity)
                                ->getCreateSyncEntityFromSignatureClosure(array_keys($data))
                            : SyncIntrospector::getService($ctx->container(), $this->Entity)
                                ->getCreateSyncEntityFromClosure();
                    }
                    /** @var TEntity */
                    $entity = $closure($data, $this->Provider, $ctx);

                    return $entity;
                }
            );
    }

    /**
     * Enforce the unclaimed filter policy
     *
     * @param OP::* $operation
     * @param array{}|null $empty
     *
     * @see SyncDefinition::$FilterPolicy
     */
    final protected function applyFilterPolicy($operation, ISyncContext $ctx, ?bool &$returnEmpty, &$empty): void
    {
        $returnEmpty = false;

        if ($this->FilterPolicy === FilterPolicy::IGNORE ||
                !($filter = $ctx->getFilters())) {
            return;
        }

        switch ($this->FilterPolicy) {
            case FilterPolicy::THROW_EXCEPTION:
                throw new SyncFilterPolicyViolationException($this->Provider, $this->Entity, $filter);

            case FilterPolicy::RETURN_EMPTY:
                $returnEmpty = true;
                $empty = OP::isList($operation) ? [] : null;

                return;

            case FilterPolicy::FILTER_LOCALLY:
                /** @todo Implement FilterPolicy::FILTER_LOCALLY */
                break;
        }

        throw new LogicException(sprintf(
            'FilterPolicy invalid or not implemented: %s',
            $this->FilterPolicy,
        ));
    }

    /**
     * @param OP::* $operation
     */
    private function getContextWithFilterCallback($operation, ISyncContext $ctx): ISyncContext
    {
        return $ctx->withFilterPolicyCallback(
            function (ISyncContext $ctx, ?bool &$returnEmpty, &$empty) use ($operation): void {
                $this->applyFilterPolicy($operation, $ctx, $returnEmpty, $empty);
            }
        );
    }

    /**
     * @param iterable<TEntity> $result
     * @return FluentIteratorInterface<array-key,TEntity>
     */
    private function getFluentIterator(iterable $result): FluentIteratorInterface
    {
        if (!($result instanceof FluentIteratorInterface)) {
            return new IterableIterator($result);
        }

        return $result;
    }

    public static function getReadableProperties(): array
    {
        return [
            'Entity',
            'Provider',
            'Operations',
            'Conformity',
            'FilterPolicy',
            'Overrides',
            'KeyMap',
            'KeyMapFlags',
            'PipelineFromBackend',
            'PipelineToBackend',
            'ReadFromReadList',
            'ReturnEntitiesFrom',
        ];
    }
}
