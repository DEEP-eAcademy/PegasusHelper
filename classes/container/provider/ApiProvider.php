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

    private function buildRouter(Container $c): Router
    {
        global $DIC;
        $router = new Router();

        $router->post('/v2/oauth2/token', new TokenController($c[TokenService::class]), Router::AUTH_NONE);

        $router->get('/v2/ilias-app/auth-token', new AuthTokenController($c[AuthTokenRepository::class]));

        $objectController = new ObjectController($c[ObjectDataMapper::class]);
        $router->get('/v2/ilias-app/desktop', [$objectController, 'desktop']);
        $router->get('/v2/ilias-app/objects/{refId}', [$objectController, 'children']);
        $router->get('/v3/ilias-app/object/{refId}', [$objectController, 'object']);

        $fileController = new FileController();
        $router->get('/v3/ilias-app/files/{refId}', [$fileController, 'metadata']);
        $router->post('/v3/ilias-app/files/{refId}/learning-progress-to-done', [$fileController, 'markLearningProgressDone']);
        $router->get('/v1/files/{refId}', [$fileController, 'download']);

        $router->get('/v3/ilias-app/theme', new ThemeController($DIC->database()));

        $router->get('/v2/ilias-app/news', new NewsController());

        $learningModuleController = new LearningModuleController($c[LearningModuleZipBuilder::class], $c[AuthTokenRepository::class]);
        $router->get('/v1/learning-module/{refId}', [$learningModuleController, 'metadata']);
        $router->get('/v1/learning-module/{refId}/zip', [$learningModuleController, 'zip'], Router::AUTH_NONE);

        return $router;
    }
}
