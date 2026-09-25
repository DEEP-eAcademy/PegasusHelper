<?php

namespace SRAG\PegasusHelper\container;

use ILIAS\DI\Container;
use SRAG\PegasusHelper\container\exception\DependencyResolutionException;
use SRAG\PegasusHelper\container\provider\ApiProvider;
use SRAG\PegasusHelper\container\provider\AuditProvider;
use SRAG\PegasusHelper\container\provider\AuthenticationProvider;
use SRAG\PegasusHelper\container\provider\Ilias6RequestHandlerProvider;

/**
 * Class PegasusHelperContainer
 *
 * @package SRAG\PegasusHelper\container
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class PegasusHelperContainer
{
    /**
     * @var Container|null $container
     */
    private static ?Container $container = null;

    /**
     * @var bool true once the providers have actually been registered (as opposed
     *           to a no-op call made before $DIC existed)
     */
    private static bool $bootstrapped = false;


    /**
     * Bootstraps the plugin dependency container, with all service providers.
     * This method requires an registered autoloader and
     * the already bootstrapped ILIAS DI container.
     *
     * Silently does nothing if the ILIAS DI container isn't available yet.
     * This file is `require_once`d at the top of every plugin class file
     * (including plain GUI classes), and ILIAS's ctrl-structure build tooling
     * loads those files via reflection outside of a real request, with no
     * `$DIC` in scope. Throwing here would abort that unrelated, unconnected
     * process; {@see resolve()} is what actually enforces bootstrap state.
     *
     * Idempotent once it has actually registered the providers: `api.php` calls
     * this explicitly right after it boots ILIAS itself (see
     * {@see \SRAG\PegasusHelper\api\ApiInitialisation::boot()}), in addition to
     * the unconditional call already made by every plugin class file via
     * `bootstrap.php`. Re-registering would otherwise risk a Pimple
     * FrozenServiceException for any service that had already been resolved
     * between the two calls.
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        if (static::$bootstrapped) {
            return;
        }

        global $DIC;
        $container = $DIC ?? ($GLOBALS['DIC'] ?? null);
        if (!$container instanceof Container) {
            return;
        }
        static::$container = $container;

        // Registered first: AuditLog is injected into services registered by
        // every provider below.
        static::$container->register(new AuditProvider());

        static::$container->register(new AuthenticationProvider());
        if (version_compare(ILIAS_VERSION_NUMERIC, '9.0', '<')) {
            throw new DependencyResolutionException('The pegasus helper plugin only supports ILIAS 9 or newer.');
        }

        static::$container->register(new Ilias6RequestHandlerProvider());
        static::$container->register(new ApiProvider());

        static::$bootstrapped = true;
    }


    /**
     * @param string $class
     * @return object
     */
    public static function resolve(string $class): object
    {
        if (static::$container === null) {
            throw new DependencyResolutionException('The pegasus helper container has not been bootstrapped.');
        }

        if (!static::$container->offsetExists($class)) {
            throw new DependencyResolutionException("The class \"$class\" was not found.");
        }

        return static::$container[$class];
    }
}
