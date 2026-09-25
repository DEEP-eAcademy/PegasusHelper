<?php

namespace SRAG\PegasusHelper\api;

use SRAG\PegasusHelper\audit\AuditLog;
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
 * Every request is audited exactly once, via a `register_shutdown_function`
 * hook rather than an inline call at the end of {@see run()}: two routes
 * (file download, learning-module zip) stream their response and call
 * `exit()` partway through, which would skip any code placed after
 * `$handler($request, $route->getParams())`. The shutdown hook runs
 * regardless of how the request ended, and its `duration_ms` also then
 * covers the time spent streaming.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiKernel
{
    /**
     * @var float
     */
    private static $startTime;

    /**
     * @var Request|null
     */
    private static $request;

    /**
     * @var MatchedRoute|null
     */
    private static $route;

    /**
     * @var Throwable|null the exception that ended the request, if any
     */
    private static $caughtException;

    /**
     * @var AuditLog|null null until the container has bootstrapped; a request
     *                     that fails before that point (e.g. ILIAS itself
     *                     failing to boot) is not audited -- {@see logError()}
     *                     still records it via error_log()/the root logger
     */
    private static $auditLog;

    /**
     * @var bool guards against the shutdown hook running twice
     */
    private static $audited = false;

    /**
     * @var array{client_id:?string, claimed_user_id:?int, token_fp:?string}
     */
    private static $peek = ['client_id' => null, 'claimed_user_id' => null, 'token_fp' => null];

    public static function run(): void
    {
        self::$startTime = microtime(true);

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
            self::$request = $request;

            self::$peek = self::peekToken($request);

            ApiInitialisation::boot(self::$peek['client_id']);

            PegasusHelperContainer::bootstrap();

            /** @var AuditLog $auditLog */
            $auditLog = PegasusHelperContainer::resolve(AuditLog::class);
            self::$auditLog = $auditLog;
            register_shutdown_function([self::class, 'auditRequest']);

            $router = PegasusHelperContainer::resolve(Router::class);
            $route = $router->match($request->getMethod(), $request->getPath());
            self::$route = $route;

            if ($route->getAuth() === Router::AUTH_BEARER) {
                /** @var TokenService $tokenService */
                $tokenService = PegasusHelperContainer::resolve(TokenService::class);
                $userId = $tokenService->validateAccess($request->getBearerToken());
                ApiInitialisation::loadUser($userId);
                $auditLog->setActor($userId);
            }

            $handler = $route->getHandler();
            $response = $handler($request, $route->getParams());

            self::send($response);
        } catch (ApiException $e) {
            self::$caughtException = $e;
            self::sendError($e->getStatusCode(), $e->getBody(), $e->getHeaders());
        } catch (Throwable $e) {
            self::$caughtException = $e;
            self::logError($e);
            self::sendError(500, ['cause' => 'Internal Server Error']);
        }
    }

    /**
     * Emits the single `api.request` audit entry for this request. Registered
     * as a shutdown handler (see {@see run()}); never runs if the container
     * never finished bootstrapping.
     */
    public static function auditRequest(): void
    {
        if (self::$auditLog === null || self::$audited) {
            return;
        }
        self::$audited = true;

        $status = http_response_code();
        $reason = null;
        $error = null;

        if (self::$caughtException instanceof ApiException) {
            $status = self::$caughtException->getStatusCode();
            $reason = self::$caughtException->getReason();
        } elseif (self::$caughtException instanceof Throwable) {
            $status = 500;
            $error = get_class(self::$caughtException);
        } elseif ($status === false) {
            // No response was ever sent and nothing was caught here: a truly
            // fatal error (out of memory, timeout) bypassed even the
            // top-level `catch (Throwable $e)` above.
            $fatal = error_get_last();
            $isFatal = $fatal !== null
                && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            $status = $isFatal ? 500 : 200;
            if ($isFatal) {
                $error = $fatal['message'];
            }
        }

        $route = self::$route;
        $request = self::$request;

        $fields = [
            'method' => $request !== null ? $request->getMethod() : ($_SERVER['REQUEST_METHOD'] ?? null),
            'route' => $route !== null ? $route->getPattern() : null,
            'path' => $request !== null ? $request->getPath() : null,
            'params' => $route !== null ? $route->getParams() : [],
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - self::$startTime) * 1000),
        ];
        if ($reason !== null) {
            $fields['reason'] = $reason;
        }
        if ($error !== null) {
            $fields['error'] = $error;
        }
        if (self::$peek['token_fp'] !== null) {
            $fields['token_fp'] = self::$peek['token_fp'];
        }
        if ($status === 401 && self::$peek['claimed_user_id'] !== null) {
            $fields['claimed_user_id'] = self::$peek['claimed_user_id'];
        }

        self::$auditLog->log(AuditLog::EVENT_API_REQUEST, self::tierFor((int) $status, $reason), $fields);
    }

    /**
     * 401 reasons that reflect routine app behaviour (an access token expiring
     * mid-session and being refreshed, or a one-time SSO token racing its own
     * short TTL) rather than a suspicious request; everything else at 401 is a
     * forged, mismatched, revoked or otherwise unexpected token presentation.
     */
    private const ROUTINE_401_REASONS = [
        'missing_token', 'expired', 'missing_refresh_token', 'missing_api_key',
        'missing_sso_token', 'sso_token_expired',
    ];

    private static function tierFor(int $status, ?string $reason): int
    {
        if ($status >= 500) {
            return AuditLog::LEVEL_ERROR;
        }
        if ($status === 403) {
            return AuditLog::LEVEL_NOTICE;
        }
        if ($status === 401) {
            return in_array($reason, self::ROUTINE_401_REASONS, true) ? AuditLog::LEVEL_INFO : AuditLog::LEVEL_WARNING;
        }

        return AuditLog::LEVEL_INFO;
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
     * Peeks (without verifying) at the token carried by the request, so the
     * correct ILIAS client can be selected before ILIAS is booted, and so a
     * fingerprint/claimed user id is available for the audit log even when
     * the token turns out to be invalid. ILIAS 10's `determineClient()` only
     * reads `$_GET['client_id']`, so this must happen before
     * {@see ApiInitialisation::boot()}, i.e. before the token's signature can
     * actually be checked (that needs the DB-stored salt).
     *
     * @param Request $request
     * @return array{client_id:?string, claimed_user_id:?int, token_fp:?string}
     */
    private static function peekToken(Request $request): array
    {
        $raw = $request->getBearerToken() ?? $request->param('refresh_token');
        if ($raw !== null && $raw !== '') {
            $normalized = TokenCodec::normalize($raw);
            $token = TokenCodec::deserialize($normalized);
            if ($token !== null) {
                return [
                    'client_id' => $token['ilias_client'] ?? null,
                    'claimed_user_id' => isset($token['user_id']) ? (int) $token['user_id'] : null,
                    'token_fp' => AuditLog::fingerprint($normalized),
                ];
            }
        }

        // The learning-module zip route authenticates via ?user=&token=
        // instead of a Bearer header (Router::AUTH_NONE); that "token" isn't
        // in TokenCodec's wire format at all, so no fingerprint is available.
        $userParam = $request->query('user');
        if ($userParam !== null && ctype_digit($userParam)) {
            return ['client_id' => null, 'claimed_user_id' => (int) $userParam, 'token_fp' => null];
        }

        return ['client_id' => null, 'claimed_user_id' => null, 'token_fp' => null];
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
