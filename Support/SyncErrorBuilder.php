<?php declare(strict_types=1);

namespace Lkrms\Sync\Support;

use Lkrms\Sync\Catalog\SyncErrorType;
use Lkrms\Sync\Contract\ISyncEntity;
use Lkrms\Sync\Contract\ISyncProvider;
use Salient\Console\Catalog\ConsoleLevel as Level;
use Salient\Core\AbstractBuilder;

/**
 * A fluent SyncError factory
 *
 * @method $this errorType(SyncErrorType::* $value) Set SyncError::$ErrorType
 * @method $this message(string $value) An sprintf() format string that explains the error (see {@see SyncError::$Message})
 * @method $this values(mixed[] $value) Values passed to sprintf() with the message format string (see {@see SyncError::$Values})
 * @method $this level(Level::* $value) Set SyncError::$Level
 * @method $this entity(?ISyncEntity $value) The entity associated with the error
 * @method $this entityName(?string $value) The display name of the entity associated with the error (see {@see SyncError::$EntityName})
 * @method $this provider(?ISyncProvider $value) The sync provider associated with the error
 *
 * @extends AbstractBuilder<SyncError>
 *
 * @generated
 */
final class SyncErrorBuilder extends AbstractBuilder
{
    /**
     * @internal
     */
    protected static function getService(): string
    {
        return SyncError::class;
    }
}
