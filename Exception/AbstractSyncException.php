<?php declare(strict_types=1);

namespace Salient\Sync\Exception;

use Salient\Contract\Sync\Exception\SyncExceptionInterface;
use Salient\Contract\Sync\SyncProviderInterface;
use Salient\Core\AbstractException;

/**
 * @internal
 */
abstract class AbstractSyncException extends AbstractException implements SyncExceptionInterface
{
    /**
     * Get the name of a provider for use in exception messages
     */
    protected function getProviderName(SyncProviderInterface $provider): string
    {
        return sprintf(
            '%s [#%d]',
            $provider->getName(),
            $provider->getProviderId(),
        );
    }
}
