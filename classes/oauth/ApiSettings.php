<?php

namespace SRAG\PegasusHelper\oauth;

use ilDBInterface;

/**
 * Class ApiSettings
 *
 * Repository for the `ui_uihk_peg_config` table, which replaces the REST
 * plugin's `ui_uihk_rest_config` for the settings this plugin needs: the
 * `ilias_pegasus` API key/secret, the token signing salt, the access/refresh
 * token TTLs (in minutes, matching the REST plugin's convention), and the
 * optional maximum login age (see {@see getMaxLoginAgeDays()}).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiSettings
{
    private const TABLE = 'ui_uihk_peg_config';

    public const KEY_API_KEY = 'api_key';
    public const KEY_API_SECRET = 'api_secret';
    public const KEY_SALT = 'salt';
    public const KEY_ACCESS_TOKEN_TTL = 'access_token_ttl';
    public const KEY_REFRESH_TOKEN_TTL = 'refresh_token_ttl';
    public const KEY_MAX_LOGIN_AGE_DAYS = 'max_login_age_days';

    /**
     * A salt shorter than this is flagged by {@see hasWeakSalt()} as a warning
     * on the General tab, but is never rejected outright or auto-rotated: an
     * existing short salt still signs real tokens, and silently invalidating
     * them would be a worse outcome than the warning.
     */
    private const MIN_STRONG_SALT_LENGTH = 32;

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function get(string $key): ?string
    {
        $set = $this->db->queryF(
            'SELECT setting_value FROM ' . self::TABLE . ' WHERE setting_name = %s',
            ['text'],
            [$key]
        );
        $row = $this->db->fetchAssoc($set);

        return $row !== null ? (string) $row['setting_value'] : null;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value !== null ? (int) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->replace(
            self::TABLE,
            ['setting_name' => ['text', $key]],
            ['setting_value' => ['text', $value]]
        );
    }

    public function getApiKey(): string
    {
        return $this->get(self::KEY_API_KEY) ?? '';
    }

    public function getApiSecret(): string
    {
        return $this->get(self::KEY_API_SECRET) ?? '';
    }

    public function getSalt(): string
    {
        return $this->get(self::KEY_SALT) ?? '';
    }

    public function getAccessTokenTtlMinutes(): int
    {
        return $this->getInt(self::KEY_ACCESS_TOKEN_TTL, 30);
    }

    public function getRefreshTokenTtlMinutes(): int
    {
        return $this->getInt(self::KEY_REFRESH_TOKEN_TTL, 525600);
    }

    /**
     * @return int the maximum age (in days) a login is allowed to reach before
     *             it can no longer be refreshed, or 0 for unlimited (the
     *             default -- see the "Maximum login age" field on the General
     *             tab and {@see \SRAG\PegasusHelper\oauth\GrantGuard})
     */
    public function getMaxLoginAgeDays(): int
    {
        return max(0, $this->getInt(self::KEY_MAX_LOGIN_AGE_DAYS, 0));
    }

    /**
     * @return string the non-empty signing salt
     * @throws MisconfigurationException if it is missing or empty (SEC-01)
     */
    public function requireSalt(): string
    {
        $salt = $this->getSalt();
        if ($salt === '') {
            throw new MisconfigurationException('The token signing salt is not configured.');
        }

        return $salt;
    }

    /**
     * @return string the non-empty `ilias_pegasus` API key
     * @throws MisconfigurationException if it is missing or empty (SEC-01)
     */
    public function requireApiKey(): string
    {
        $key = $this->getApiKey();
        if ($key === '') {
            throw new MisconfigurationException('The API key is not configured.');
        }

        return $key;
    }

    /**
     * @return string the non-empty `ilias_pegasus` API secret
     * @throws MisconfigurationException if it is missing or empty -- an empty
     *         configured secret would otherwise let `hash_equals('', '')`
     *         accept a refresh request with no `api_secret` at all (SEC-01)
     */
    public function requireApiSecret(): string
    {
        $secret = $this->getApiSecret();
        if ($secret === '') {
            throw new MisconfigurationException('The API secret is not configured.');
        }

        return $secret;
    }

    /**
     * @return bool true if the configured salt is non-empty but shorter than a
     *              reasonable minimum -- surfaced only as an admin-facing
     *              warning (see the General tab), never enforced
     */
    public function hasWeakSalt(): bool
    {
        $salt = $this->getSalt();

        return $salt !== '' && strlen($salt) < self::MIN_STRONG_SALT_LENGTH;
    }
}
