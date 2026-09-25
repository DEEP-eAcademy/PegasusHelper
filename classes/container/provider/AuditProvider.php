<?php

namespace SRAG\PegasusHelper\container\provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use SRAG\PegasusHelper\audit\AuditLog;

/**
 * Class AuditProvider
 *
 * Registers {@see AuditLog} as a *shared* service (not a factory): its
 * `request_id` and the actor set via `setActor()` must stay the same for
 * every audit entry written while handling one request.
 *
 * @package SRAG\PegasusHelper\container\provider
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class AuditProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(Container $pimple)
    {
        $pimple[AuditLog::class] = function ($c) {
            return new AuditLog();
        };
    }
}
