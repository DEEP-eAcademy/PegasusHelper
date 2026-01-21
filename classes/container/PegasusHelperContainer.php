<?php

namespace SRAG\PegasusHelper\container;

use ILIAS\DI\Container;
use SRAG\PegasusHelper\container\exception\DependencyResolutionException;
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
     * Bootstraps the plugin dependency container, with all service providers.
     * This method requires an registered autoloader and
     * the already bootstrapped ILIAS DI container.
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        global $DIC;
        static::$container = $DIC ?? ($GLOBALS['DIC'] ?? null);
        if (!static::$container instanceof Container) {
            throw new DependencyResolutionException('The ILIAS DI container is not available.');
        }

        static::$container->register(new AuthenticationProvider());
        if (version_compare(ILIAS_VERSION_NUMERIC, '9.0', '<')) {
            throw new DependencyResolutionException('The pegasus helper plugin only supports ILIAS 9 or newer.');
        }

        static::$container->register(new Ilias6RequestHandlerProvider());
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
