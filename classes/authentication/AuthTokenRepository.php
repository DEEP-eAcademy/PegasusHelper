<?php

namespace SRAG\PegasusHelper\authentication;

use ilDBInterface;

/**
 * Class AuthTokenRepository
 *
 * Repository for the short-lived, one-time SSO auth-tokens the app fetches via
 * `GET /v2/ilias-app/auth-token` before opening an ILIAS page in the browser or
 * downloading a resource. Replaces the REST plugin's `ui_uihk_rest_token` table
 * and the broken {@code entity\UserToken} ActiveRecord.
 *
 * Unlike the REST plugin (one token per user, generated from `hash('sha512',
 * rand(100,10000)*17+userId)` -- about 9'900 possible values per user), tokens
 * here are drawn from a CSPRNG and several can be valid for the same user at
 * once, since the app requests a fresh token for every link/resource it builds
 * and can do so concurrently.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class AuthTokenRepository
{
    private const TABLE = 'ui_uihk_peg_token';
    private const TTL_SECONDS = 60;

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Creates a new one-time token for the given user, valid for 60 seconds.
     *
     * @param int $userId
     * @return string the token
     */
    public function create(int $userId): string
    {
        $this->purgeExpired();

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (token, user_id, expires) VALUES (%s, %s, %s)',
            ['text', 'integer', 'timestamp'],
            [$token, $userId, $expires]
        );

        return $token;
    }

    /**
     * Validates and deletes a token in one step. The token is always deleted,
     * regardless of whether it was valid, matching the previous behaviour.
     *
     * @param int    $userId
     * @param string $token
     * @return bool true if the token existed, matched the user and had not expired
     */
    public function consume(int $userId, string $token): bool
    {
        $set = $this->db->queryF(
            'SELECT expires FROM ' . self::TABLE . ' WHERE token = %s AND user_id = %s',
            ['text', 'integer'],
            [$token, $userId]
        );
        $row = $this->db->fetchAssoc($set);

        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE token = %s AND user_id = %s',
            ['text', 'integer'],
            [$token, $userId]
        );

        if ($row === null) {
            return false;
        }

        return strtotime($row['expires']) > time();
    }

    private function purgeExpired(): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE expires < %s',
            ['timestamp'],
            [date('Y-m-d H:i:s')]
        );
    }
}
