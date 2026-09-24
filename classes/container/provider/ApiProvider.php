<?php

namespace SRAG\PegasusHelper\container\provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use SRAG\PegasusHelper\api\controller\AuthTokenController;
use SRAG\PegasusHelper\api\controller\FileController;
use SRAG\PegasusHelper\api\controller\LearningModuleController;
use SRAG\PegasusHelper\api\controller\NewsController;
use SRAG\PegasusHelper\api\controller\ObjectController;
use SRAG\PegasusHelper\api\controller\ThemeController;
use SRAG\PegasusHelper\api\controller\TokenController;
use SRAG\PegasusHelper\api\LearningModuleZipBuilder;
use SRAG\PegasusHelper\api\ObjectDataMapper;
use SRAG\PegasusHelper\api\Router;
use SRAG\PegasusHelper\authentication\AuthTokenRepository;
use SRAG\PegasusHelper\oauth\ApiSettings;
use SRAG\PegasusHelper\oauth\RefreshTokenRepository;
use SRAG\PegasusHelper\oauth\RevocationRepository;
use SRAG\PegasusHelper\oauth\TokenCodec;
use SRAG\PegasusHelper\oauth\TokenService;

/**
 * Class ApiProvider
 *
 * Registers the services and the route table used by `api.php`
 * ({@see \SRAG\PegasusHelper\api\ApiKernel}).
 *
 * @package SRAG\PegasusHelper\container\provider
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(Container $pimple)
    {
        $pimple[ApiSettings::class] = $pimple->factory(function ($c) {
            global $DIC;

            return new ApiSettings($DIC->database());
        });

        $pimple[TokenCodec::class] = $pimple->factory(function ($c) {
            return new TokenCodec($c[ApiSettings::class]->getSalt());
        });

        $pimple[RefreshTokenRepository::class] = $pimple->factory(function ($c) {
            global $DIC;

            return new RefreshTokenRepository($DIC->database());
        });

        $pimple[RevocationRepository::class] = $pimple->factory(function ($c) {
            global $DIC;

            return new RevocationRepository($DIC->database());
        });

        $pimple[TokenService::class] = $pimple->factory(function ($c) {
            return new TokenService(
                $c[TokenCodec::class],
                $c[ApiSettings::class],
                $c[RefreshTokenRepository::class],
                $c[RevocationRepository::class]
            );
        });

        $pimple[ObjectDataMapper::class] = $pimple->factory(function ($c) {
            global $DIC;

            return new ObjectDataMapper($DIC->database(), $DIC->access());
        });

        $pimple[LearningModuleZipBuilder::class] = $pimple->factory(function ($c) {
            global $DIC;

            return new LearningModuleZipBuilder($DIC->database());
        });

        $pimple[Router::class] = $pimple->factory(function ($c) {
            return $this->buildRouter($c);
        });
    }

    /**
     * Builds the route table. Every controller is constructed lazily, inside
     * the handler closure actually registered with the router, rather than
     * eagerly here -- some controllers' dependencies (e.g. ObjectDataMapper
     * needs $DIC->access()) only become available once ApiKernel has resolved
     * the route AND (for a Bearer route) called ApiInitialisation::loadUser().
     * Building them eagerly here, before either of those has happened, throws
     * Pimple\Exception\UnknownIdentifierException for "ilAccess", because
     * ilAccess is only registered in the DIC by initAccessHandling(), which
     * loadUser() triggers.
     */
    private function buildRouter(Container $c): Router
    {
        $router = new Router();

        $router->post('/v2/oauth2/token', function ($request, $params) use ($c) {
            return (new TokenController($c[TokenService::class]))($request, $params);
        }, Router::AUTH_NONE);

        $router->get('/v2/ilias-app/auth-token', function ($request, $params) use ($c) {
            return (new AuthTokenController($c[AuthTokenRepository::class]))($request, $params);
        });

        $objectController = function () use ($c) {
            return new ObjectController($c[ObjectDataMapper::class]);
        };
        $router->get('/v2/ilias-app/desktop', function ($request, $params) use ($objectController) {
            return $objectController()->desktop($request, $params);
        });
        $router->get('/v2/ilias-app/objects/{refId}', function ($request, $params) use ($objectController) {
            return $objectController()->children($request, $params);
        });
        $router->get('/v3/ilias-app/object/{refId}', function ($request, $params) use ($objectController) {
            return $objectController()->object($request, $params);
        });

        $fileController = static function (): FileController {
            return new FileController();
        };
        $router->get('/v3/ilias-app/files/{refId}', function ($request, $params) use ($fileController) {
            return $fileController()->metadata($request, $params);
        });
        $router->post('/v3/ilias-app/files/{refId}/learning-progress-to-done', function ($request, $params) use ($fileController) {
            return $fileController()->markLearningProgressDone($request, $params);
        });
        $router->get('/v1/files/{refId}', function ($request, $params) use ($fileController) {
            return $fileController()->download($request, $params);
        });

        $router->get('/v3/ilias-app/theme', function ($request, $params) {
            global $DIC;

            return (new ThemeController($DIC->database()))($request, $params);
        });

        $router->get('/v2/ilias-app/news', function ($request, $params) {
            return (new NewsController())($request, $params);
        });

        $learningModuleController = function () use ($c) {
            return new LearningModuleController($c[LearningModuleZipBuilder::class], $c[AuthTokenRepository::class]);
        };
        $router->get('/v1/learning-module/{refId}', function ($request, $params) use ($learningModuleController) {
            return $learningModuleController()->metadata($request, $params);
        });
        $router->get('/v1/learning-module/{refId}/zip', function ($request, $params) use ($learningModuleController) {
            return $learningModuleController()->zip($request, $params);
        }, Router::AUTH_NONE);

        return $router;
    }
}
