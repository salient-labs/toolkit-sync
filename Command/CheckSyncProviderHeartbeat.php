<?php declare(strict_types=1);

namespace Salient\Sync\Command;

use Salient\Cli\Catalog\CliOptionType;
use Salient\Cli\Catalog\CliOptionValueType;
use Salient\Cli\Exception\CliInvalidArgumentsException;
use Salient\Cli\CliOption;
use Salient\Core\Facade\Console;
use Salient\Core\Utility\Inflect;
use Salient\Sync\Contract\SyncProviderInterface;

/**
 * A generic sync provider heartbeat check command
 */
final class CheckSyncProviderHeartbeat extends AbstractSyncCommand
{
    /**
     * @var string[]
     */
    private array $ProviderBasename = [];

    /**
     * @var array<class-string<SyncProviderInterface>>
     */
    private array $Provider = [];

    private int $Ttl = 0;

    private bool $FailEarly = false;

    public function description(): string
    {
        return
            'Send a heartbeat request to ' . (
                count($this->Providers) > 1
                    ? 'one or more providers'
                    : 'a provider'
            );
    }

    protected function getOptionList(): array
    {
        $optB = CliOption::build()
            ->long('provider')
            ->valueName('provider')
            ->description('The provider to check')
            ->multipleAllowed();

        if ($this->Providers) {
            $optB = $optB
                ->optionType(CliOptionType::ONE_OF_POSITIONAL)
                ->allowedValues(array_keys($this->Providers))
                ->addAll()
                ->defaultValue('ALL')
                ->bindTo($this->ProviderBasename);
        } else {
            $optB = $optB
                ->optionType(CliOptionType::VALUE_POSITIONAL)
                ->required()
                ->bindTo($this->Provider);
        }

        return [
            $optB,
            CliOption::build()
                ->long('ttl')
                ->short('t')
                ->valueName('seconds')
                ->description('The time-to-live of a positive result')
                ->optionType(CliOptionType::VALUE)
                ->valueType(CliOptionValueType::INTEGER)
                ->defaultValue(300)
                ->bindTo($this->Ttl),
            CliOption::build()
                ->long('fail-early')
                ->short('f')
                ->description('If a check fails, exit without checking other providers')
                ->bindTo($this->FailEarly),
        ];
    }

    public function getLongDescription(): ?string
    {
        !$this->Providers ||
            $description =
                <<<EOF
If no providers are given, all providers are checked.


EOF;

        return
            ($description ?? '') . <<<EOF
If a heartbeat request fails, __{{command}}__ continues to the next
provider unless `-f/--fail-early` is given, in which case it exits
immediately.

The command exits with a non-zero status if a provider backend is
unreachable.
EOF;
    }

    protected function run(string ...$args)
    {
        Console::registerStderrTarget(true);

        if ($this->Providers) {
            $providers =
                array_map(
                    fn(string $providerClass) =>
                        $this->App->get($providerClass),
                    array_intersect_key(
                        $this->Providers,
                        array_flip($this->ProviderBasename)
                    )
                );
        } else {
            $providers =
                array_map(
                    function (string $providerClass) {
                        if (is_a(
                            $this->App->getName($providerClass),
                            SyncProviderInterface::class,
                            true
                        )) {
                            return $this->App->get($providerClass);
                        }

                        throw new CliInvalidArgumentsException(sprintf(
                            '%s does not implement %s',
                            $providerClass,
                            SyncProviderInterface::class
                        ));
                    },
                    $this->Provider
                );
        }

        $count = count($providers);

        Console::info(Inflect::format(
            $count,
            'Sending heartbeat request to {{#}} {{#:provider}}',
        ));

        $this->Store->checkHeartbeats(
            max(1, $this->Ttl),
            $this->FailEarly,
            ...array_values($providers)
        );

        Console::summary(Inflect::format(
            $count,
            '{{#}} {{#:provider}} checked',
        ));
    }
}
