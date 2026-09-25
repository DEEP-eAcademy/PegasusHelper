<?php

namespace SRAG\PegasusHelper\audit;

use ilLogger;
use ilLoggerFactory;
use ilObjUser;
use Throwable;

/**
 * Class AuditLog
 *
 * Writes a structured, single-line audit entry (`PEGASUS_AUDIT {json}`) to a
 * dedicated ILIAS log channel (see {@see CHANNEL}) for every security- or
 * audit-relevant event in the plugin: API requests, logins, token issuance/
 * refresal/rejection, downloads, and admin configuration changes.
 *
 * Deliberately decoupled from `\ilLogLevel`: the LEVEL_* constants below
 * mirror its documented integer values so callers don't need that class
 * loaded (or ILIAS booted at all) to reference a level. `dbupdate.php` seeds
 * a `log_components` row for {@see CHANNEL} at LEVEL_INFO, so entries are
 * written even on installs whose global log level default is higher; an
 * admin can still raise or lower it from Administration > Logging.
 *
 * Never put a raw access/refresh/SSO token, the API secret, or the signing
 * salt into a logged entry -- {@see fingerprint()} is the only token-derived
 * value that belongs in a log line, and {@see sanitize()} additionally strips
 * a fixed list of sensitive field names as a defence in depth. Every write is
 * wrapped so that a logging failure can never break the request it is
 * auditing (it falls back to `error_log()`).
 *
 * Registered as a *shared* Pimple service (see AuditProvider), so `request_id`
 * and the actor set via {@see setActor()} persist across every log() call
 * made while handling one request.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class AuditLog
{
    public const CHANNEL = 'sragpegasushelper';
    public const MARKER = 'PEGASUS_AUDIT';

    // Mirrors \ilLogLevel's documented integer values (DEBUG=100, INFO=200,
    // NOTICE=250, WARNING=300, ERROR=400, ...), so this class has no hard
    // dependency on that ILIAS class existing merely to log at a given level.
    public const LEVEL_INFO = 200;
    public const LEVEL_NOTICE = 250;
    public const LEVEL_WARNING = 300;
    public const LEVEL_ERROR = 400;

    public const EVENT_API_REQUEST = 'api.request';
    public const EVENT_APP_LOGIN = 'auth.app_login';
    public const EVENT_TOKEN_REFRESH = 'auth.token_refresh';
    public const EVENT_TOKEN_REPLAY = 'auth.token_replay';
    public const EVENT_SSO_LOGIN = 'auth.sso_login';
    public const EVENT_SSO_LOGIN_FAILED = 'auth.sso_login_failed';
    public const EVENT_SESSION_TERMINATED = 'auth.session_terminated';
    public const EVENT_FILE_DOWNLOAD = 'content.file_download';
    public const EVENT_LM_DOWNLOAD = 'content.lm_download';
    public const EVENT_ADMIN_API_SETTINGS_CHANGED = 'admin.api_settings_changed';
    public const EVENT_ADMIN_TOKENS_REVOKED = 'admin.tokens_revoked';
    public const EVENT_ADMIN_SALT_ROTATED = 'admin.signing_salt_rotated';
    public const EVENT_ADMIN_THEME_CHANGED = 'admin.theme_changed';
    public const EVENT_ADMIN_REQUEST_REJECTED = 'admin.request_rejected';

    /**
     * Field names stripped from every logged entry, regardless of where they
     * appear (recursively), as a guard against a future call site accidentally
     * passing a raw secret through.
     */
    private const FORBIDDEN_KEYS = [
        'token', 'access_token', 'refresh_token', 'api_secret', 'secret', 'salt', 'password',
    ];

    private const MAX_STRING_LENGTH = 512;

    /**
     * @var string random per-instance id, correlating every event logged
     *             while handling one request
     */
    private $requestId;

    /**
     * @var int|null explicitly set actor, see {@see setActor()}
     */
    private $actorUserId;

    /**
     * @var string|null
     */
    private $actorLogin;

    /**
     * @var ilLogger|null lazily resolved, see {@see logger()}
     */
    private $logger;

    public function __construct()
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    /**
     * Sets the authenticated actor for every subsequent log() call on this
     * instance. Use this wherever the actor is only known after some
     * validation step has already run (e.g. once a Bearer token has been
     * verified), rather than relying on the ILIAS session user.
     *
     * @param int $userId
     */
    public function setActor(int $userId): void
    {
        $this->actorUserId = $userId;
        $this->actorLogin = null;

        try {
            if (class_exists(ilObjUser::class)) {
                $login = ilObjUser::_lookupLogin($userId);
                $this->actorLogin = $login !== '' ? $login : null;
            }
        } catch (Throwable $ignored) {
            // Best-effort only; the entry still carries the user id.
        }
    }

    /**
     * Writes one audit entry. Never throws -- a logging failure falls back to
     * `error_log()` rather than ever masking or breaking the caller's own
     * request handling.
     *
     * @param string $event  one of the EVENT_* constants
     * @param int    $level  one of the LEVEL_* constants
     * @param array  $fields event-specific fields, merged after the common
     *                       ones (see class docblock for what must never be
     *                       included)
     */
    public function log(string $event, int $level, array $fields = []): void
    {
        $entry = array_merge(['event' => $event], $this->baseFields(), $this->sanitize($fields));

        try {
            $logger = $this->logger();
            if ($logger !== null) {
                $logger->log(self::MARKER . ' ' . $this->encode($entry), $level);

                return;
            }
        } catch (Throwable $ignored) {
            // fall through to error_log below
        }

        error_log(self::MARKER . ' ' . $this->encode($entry));
    }

    /**
     * A short, non-reversible fingerprint of a token, safe to log: the first
     * 16 hex characters of sha256(normalized token string). For refresh
     * tokens this is a prefix of {@see \SRAG\PegasusHelper\oauth\RefreshTokenRepository}'s
     * own `token_hash` column, so a log line can be correlated with its DB row
     * without either of them revealing the token itself.
     *
     * @param string $normalizedToken as returned by TokenCodec::normalize()
     * @return string
     */
    public static function fingerprint(string $normalizedToken): string
    {
        return substr(hash('sha256', $normalizedToken), 0, 16);
    }

    /**
     * Reports whether (and how) audit entries are currently being written, for
     * display on the plugin's admin 'General' tab. Best-effort: every lookup
     * is guarded, since the exact `ilLoggingSettings`/`ilLoggerFactory` shape
     * has only been verified against the ILIAS 10 `release_10` source, not
     * against a live instance.
     *
     * @return array{available:bool, logging_enabled?:bool, log_dir?:?string,
     *               log_file?:?string, lowest_level_written?:?string,
     *               cache_enabled?:bool, error?:string}
     */
    public function status(): array
    {
        try {
            if (!class_exists(ilLoggerFactory::class)) {
                return ['available' => false];
            }

            $factory = ilLoggerFactory::getInstance();
            if (!$factory->isLoggingEnabled()) {
                return ['available' => true, 'logging_enabled' => false];
            }

            $logger = $this->logger();
            $tiers = [
                'INFO' => self::LEVEL_INFO,
                'NOTICE' => self::LEVEL_NOTICE,
                'WARNING' => self::LEVEL_WARNING,
                'ERROR' => self::LEVEL_ERROR,
            ];
            $lowestWritten = null;
            foreach ($tiers as $name => $level) {
                if ($logger !== null && $logger->isHandling($level)) {
                    $lowestWritten = $name;
                    break;
                }
            }

            $settings = method_exists($factory, 'getSettings') ? $factory->getSettings() : null;
            $cacheEnabled = ($settings !== null && method_exists($settings, 'isCacheEnabled'))
                ? (bool) $settings->isCacheEnabled()
                : false;

            return [
                'available' => true,
                'logging_enabled' => true,
                'log_dir' => defined('ILIAS_LOG_DIR') ? ILIAS_LOG_DIR : null,
                'log_file' => defined('ILIAS_LOG_FILE') ? ILIAS_LOG_FILE : null,
                'lowest_level_written' => $lowestWritten,
                'cache_enabled' => $cacheEnabled,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    private function baseFields(): array
    {
        $userId = $this->actorUserId;
        $login = $this->actorLogin;

        if ($userId === null) {
            [$userId, $login] = $this->sessionActor();
        }

        return [
            'ts' => gmdate('c'),
            'request_id' => $this->requestId,
            'client' => defined('CLIENT_ID') ? CLIENT_ID : null,
            'user_id' => $userId,
            'login' => $login,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'forwarded_for' => $this->truncate($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null),
            'user_agent' => $this->truncate($_SERVER['HTTP_USER_AGENT'] ?? null),
        ];
    }

    /**
     * @return array{0:int|null,1:string|null}
     */
    private function sessionActor(): array
    {
        try {
            global $DIC;
            if (!isset($DIC) || !is_object($DIC) || !$DIC->offsetExists('ilUser')) {
                return [null, null];
            }
            $user = $DIC->user();
            $id = (int) $user->getId();
            if ($id <= 0 || (defined('ANONYMOUS_USER_ID') && $id === ANONYMOUS_USER_ID)) {
                return [null, null];
            }

            return [$id, (string) $user->getLogin()];
        } catch (Throwable $ignored) {
            return [null, null];
        }
    }

    private function logger(): ?ilLogger
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        if (!class_exists(ilLoggerFactory::class)) {
            return null;
        }

        global $DIC;
        if (!isset($DIC) || !is_object($DIC) || !$DIC->offsetExists('ilLoggerFactory')) {
            return null;
        }

        $this->logger = ilLoggerFactory::getLogger(self::CHANNEL);

        return $this->logger;
    }

    private function encode(array $entry): string
    {
        $json = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json !== false ? $json : '{"encode_error":true}';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $v) {
                if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                    continue;
                }
                $result[$key] = $this->sanitize($v);
            }

            return $result;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        return $value;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '…';
    }
}
