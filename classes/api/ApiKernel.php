<?php

namespace SRAG\PegasusHelper\api;

use SRAG\PegasusHelper\container\PegasusHelperContainer;
use SRAG\PegasusHelper\oauth\TokenCodec;
use SRAG\PegasusHelper\oauth\TokenService;
use Throwable;

/**
 * Class ApiKernel
 *
 * Entry point logic for `api.php`. Replaces the Slim application the ILIAS REST
 * plugin used to serve the routes the Pegasus app needs.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiKernel
{
    public static function run(): void
    {
        self::sendCorsHeaders();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);

            return;
        }

        try {
            // Must happen before ApiInitialisation is referenced at all, below:
            // that class declares `extends \ilInitialisation`, and PHP resolves
            // an "extends" clause the moment it compiles the file (triggered by
            // autoloading on first reference), not lazily once some method of
            // it actually runs. Without ILIAS's own classmap loaded yet, that
            // resolution fails with "Class ilInitialisation not found" before
            // boot() ever starts.
            self::loadIliasAutoloader();

            $request = Request::fromGlobals();

            ApiInitialisation::boot(self::peekClientId($request));

            PegasusHelperContainer::bootstrap();

            $router = PegasusHelperContainer::resolve(Router::class);
            $route = $router->match($request->getMethod(), $request->getPath());

            if ($route->getAuth() === Router::AUTH_BEARER) {
                /** @var TokenService $tokenService */
                $tokenService = PegasusHelperContainer::resolve(TokenService::class);
                $userId = $tokenService->validateAccess($request->getBearerToken());
                ApiInitialisation::loadUser($userId);
            }

            $handler = $route->getHandler();
            $response = $handler($request, $route->getParams());

            self::send($response);
        } catch (ApiException $e) {
            self::sendError($e->getStatusCode(), $e->getBody(), $e->getHeaders());
        } catch (Throwable $e) {
            self::logError($e);
            self::sendError(500, ['cause' => 'Internal Server Error']);
        }
    }

    /**
     * Locates ILIAS's public webroot (the directory containing `ilias.php`)
     * by walking up from this file's location, and requires that
     * installation's own composer autoloader.
     *
     * This duplicates {@see ApiInitialisation}'s own (private) copy of the same
     * walk-up logic, which it separately needs for `chdir()`, defining
     * `ILIAS_ABSOLUTE_PATH`, and reading `ilias.ini.php`. The duplication is
     * deliberate: that logic can't be shared by calling into
     * `ApiInitialisation` here, because merely referencing that class is
     * exactly what needs ILIAS's autoloader to already be loaded (see the call
     * site in {@see run()}) -- this class must have no such dependency itself.
     */
    private static function loadIliasAutoloader(): void
    {
        $dir = __DIR__;
        for ($i = 0; $i < 20; $i++) {
            if (is_file($dir . '/ilias.php')) {
                $autoloader = dirname($dir) . '/vendor/composer/vendor/autoload.php';
                if (is_file($autoloader)) {
                    require_once $autoloader;
                }

                return;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Could not locate the ILIAS public/ webroot above ' . __DIR__);
    }

    /**
     * Peeks (without verifying) at the `ilias_client` field carried in the
     * request's Bearer token or `refresh_token` form field, so the correct
     * ILIAS client can be selected before ILIAS is booted. ILIAS 10's
     * `determineClient()` only reads `$_GET['client_id']`, so this must
     * happen before {@see ApiInitialisation::boot()}, i.e. before the token's
     * signature can actually be checked (that needs the DB-stored salt).
     *
     * @param Request $request
     * @return string|null
     */
    private static function peekClientId(Request $request): ?string
    {
        $raw = $request->getBearerToken() ?? $request->param('refresh_token');
        if ($raw === null || $raw === '') {
            return null;
        }

        $token = TokenCodec::deserialize(TokenCodec::normalize($raw));

        return $token['ilias_client'] ?? null;
    }

    /**
     * The WebView origins the ILIAS-Pegasus app's Angular HTTP client actually
     * sends (its native downloader is a separate transport not subject to CORS
     * at all, see file-download.ts). A capacitor:// origin is included for a
     * future Capacitor-based build; it is unused today but harmless to allow.
     */
    private const ALLOWED_APP_ORIGINS = [
        'ionic://localhost',
        'capacitor://localhost',
        'http://localhost',
        'https://localhost',
    ];

    private static function sendCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, self::ALLOWED_APP_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Headers: Authorization, Accept, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Expose-Headers: ETag');
    }

    private static function send(JsonResponse $response): void
    {
        http_response_code($response->getStatusCode());
        header('Content-Type: application/json');
        echo json_encode($response->getBody());
    }

    private static function sendError(int $statusCode, array $body, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($body);
    }

    private static function logError(Throwable $e): void
    {
        try {
            global $DIC;
            if (isset($DIC) && $DIC->offsetExists('ilLoggerFactory')) {
                $DIC->logger()->root()->error((string) $e);

                return;
            }
        } catch (Throwable $ignored) {
            // fall through to error_log below; logging must never mask the
            // original error response.
        }

        error_log((string) $e);
    }
}
