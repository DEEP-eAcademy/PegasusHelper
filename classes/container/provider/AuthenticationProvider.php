<?php

namespace SRAG\PegasusHelper\container\provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use SRAG\PegasusHelper\audit\AuditLog;
use SRAG\PegasusHelper\authentication\AuthTokenRepository;
use SRAG\PegasusHelper\authentication\DefaultUserTokenAuthenticator;
use SRAG\PegasusHelper\authentication\UserTokenAuthenticator;
use SRAG\PegasusHelper\oauth\GrantGuard;

/**
 * Class AuthenticationProvider
 *
 * @package SRAG\PegasusHelper\container\provider
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class AuthenticationProvider implements ServiceProviderInterface
{

    /**
     * @inheritDoc
     */
    public function register(Container $pimple)
    {
        $pimple[AuthTokenRepository::class] = $pimple->factory(function ($c) {
            global $DIC;

            // GrantGuard is registered by ApiProvider; safe to reference here
            // regardless of provider registration order, since Pimple
            // factories are only ever invoked lazily, after every provider
            // has registered (see PegasusHelperContainer::bootstrap()).
            return new AuthTokenRepository($DIC->database(), $c[GrantGuard::class]);
        });

        $pimple[UserTokenAuthenticator::class] = function ($c) {
            return new DefaultUserTokenAuthenticator($c[AuthTokenRepository::class], $c[AuditLog::class]);
        };
    }
}
