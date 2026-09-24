<?php

namespace SRAG\PegasusHelper\oauth;

use ilDBInterface;

/**
 * Class ApiSettings
 *
 * Repository for the `ui_uihk_pegasus_config` table, which replaces the REST
 * plugin's `ui_uihk_rest_config` for the settings this plugin needs: the
 * `ilias_pegasus` API key/secret, the token signing salt and the access/refresh
 * token TTLs (in minutes, matching the REST plugin's convention).
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
}
