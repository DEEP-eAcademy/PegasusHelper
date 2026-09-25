<?php

namespace SRAG\PegasusHelper\migration;

use ilDBInterface;
use SRAG\PegasusHelper\oauth\ApiSettings;
use SRAG\PegasusHelper\oauth\RefreshTokenRepository;
use SRAG\PegasusHelper\oauth\TokenCodec;

/**
 * Class RestPluginMigration
 *
 * Run once, from `sql/dbupdate.php`, when PegasusHelper stops depending on the
 * ILIAS REST plugin. If the REST plugin's tables are still present (the README
 * asks admins to update PegasusHelper before uninstalling REST, precisely so
 * this can run), it copies the `ilias_pegasus` client's secret, the token
 * signing salt and the token TTLs, so tokens already on users' devices keep
 * working; otherwise it generates fresh settings, with much shorter default
 * TTLs than the REST plugin's `classes/rest/RestSetup.php` used to (see the
 * DEFAULT_* constants below).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class RestPluginMigration
{
    private const REST_API_KEY = 'ilias_pegasus';
    // Deliberately much shorter than the REST plugin's own defaults (which were
    // ~6.8 and ~8.6 years -- see RestSetup's historical dbupdate steps #2/#3).
    // These only apply to a brand-new install with no REST plugin data to copy;
    // an admin can change them any time via the plugin's General configuration
    // tab, and a leaked access token now expires in an hour rather than years.
    private const DEFAULT_ACCESS_TOKEN_TTL = '60';        // 1 hour
    private const DEFAULT_REFRESH_TOKEN_TTL = '129600';   // 90 days

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function migrateSettings(): void
    {
        $settings = new ApiSettings($this->db);
        $client = $this->fetchRestClient();

        if ($client !== null) {
            $legacySecret = (string) $client['api_secret'];
            $legacySalt = $this->fetchRestConfig('salt');

            $settings->set(ApiSettings::KEY_API_KEY, self::REST_API_KEY);
            // An explicitly *empty* legacy secret/salt is just as unusable as a
            // missing one -- generate a fresh value rather than migrating an
            // empty string that would let every signature check pass, or let
            // `hash_equals('', '')` accept a refresh request with no secret at
            // all (SEC-01). '??' alone would not catch this: it only replaces
            // null, not ''.
            $settings->set(ApiSettings::KEY_API_SECRET, $legacySecret !== '' ? $legacySecret : $this->generateSecret());
            $settings->set(ApiSettings::KEY_SALT, ($legacySalt !== null && $legacySalt !== '') ? $legacySalt : bin2hex(random_bytes(32)));
            $settings->set(ApiSettings::KEY_ACCESS_TOKEN_TTL, $this->fetchRestConfig('access_token_ttl') ?? self::DEFAULT_ACCESS_TOKEN_TTL);
            $settings->set(ApiSettings::KEY_REFRESH_TOKEN_TTL, $this->fetchRestConfig('refresh_token_ttl') ?? self::DEFAULT_REFRESH_TOKEN_TTL);

            $this->log('migrated OAuth settings from the REST plugin.');

            return;
        }

        $settings->set(ApiSettings::KEY_API_KEY, self::REST_API_KEY);
        $settings->set(ApiSettings::KEY_API_SECRET, $this->generateSecret());
        $settings->set(ApiSettings::KEY_SALT, bin2hex(random_bytes(32)));
        $settings->set(ApiSettings::KEY_ACCESS_TOKEN_TTL, self::DEFAULT_ACCESS_TOKEN_TTL);
        $settings->set(ApiSettings::KEY_REFRESH_TOKEN_TTL, self::DEFAULT_REFRESH_TOKEN_TTL);

        $this->log('generated new OAuth settings (no REST plugin client found).');
    }

    /**
     * Migrates the REST plugin's live refresh tokens, so app installations that
     * are already logged in stay logged in after the switch. Access tokens need
     * no migration: they are stateless, and validated with the salt copied above.
     */
    public function migrateRefreshTokens(): void
    {
        if (!$this->db->tableExists('ui_uihk_rest_refresh')) {
            return;
        }

        $apiKey = (new ApiSettings($this->db))->getApiKey();
        $refreshTokens = new RefreshTokenRepository($this->db);

        $set = $this->db->query('SELECT token, created, last_refresh, refreshes FROM ui_uihk_rest_refresh');
        $migrated = 0;
        while ($row = $this->db->fetchAssoc($set)) {
            $tokenString = (string) $row['token'];
            $token = TokenCodec::deserialize($tokenString);
            if ($token === null
                || ($token['api_key'] ?? null) !== $apiKey
                || ($token['class'] ?? null) !== TokenCodec::CLASS_REFRESH
            ) {
                continue;
            }

            $refreshTokens->insertMigrated(
                $tokenString,
                (int) $token['user_id'],
                (string) $row['created'],
                (string) $row['last_refresh'],
                (int) $row['refreshes']
            );
            $migrated++;
        }

        $this->log("migrated $migrated refresh token(s) from the REST plugin.");
    }

    private function fetchRestClient(): ?array
    {
        if (!$this->db->tableExists('ui_uihk_rest_client')) {
            return null;
        }

        $set = $this->db->queryF(
            'SELECT * FROM ui_uihk_rest_client WHERE api_key = %s',
            ['text'],
            [self::REST_API_KEY]
        );

        $row = $this->db->fetchAssoc($set);

        return $row !== null ? $row : null;
    }

    private function fetchRestConfig(string $key): ?string
    {
        if (!$this->db->tableExists('ui_uihk_rest_config')) {
            return null;
        }

        $set = $this->db->queryF(
            'SELECT setting_value FROM ui_uihk_rest_config WHERE setting_name = %s',
            ['text'],
            [$key]
        );
        $row = $this->db->fetchAssoc($set);

        return $row !== null ? (string) $row['setting_value'] : null;
    }

    /**
     * Generates a secret in the same 'xxxx.xxxx-xx' shape RestSetup used to,
     * drawn from a CSPRNG instead of RestSetup's off-by-one `rand()` call.
     */
    private function generateSecret(): string
    {
        $alphabet = '123456789abcdefghijklmnopqrstuvwxyz';
        $chunk = static function (int $length) use ($alphabet): string {
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $result;
        };

        return $chunk(4) . '.' . $chunk(4) . '-' . $chunk(2);
    }

    private function log(string $message): void
    {
        global $ilLog;
        if (isset($ilLog)) {
            $ilLog->write('Plugin PegasusHelper -> ' . $message);
        }
    }
}
