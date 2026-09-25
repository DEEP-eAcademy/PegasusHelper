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

    /** The token existed, matched the user, and had not expired. */
    public const STATUS_CONSUMED = 'consumed';
    /** The token never existed, was already used, or belonged to another user -- these are indistinguishable by design (the row is looked up by token+user together). */
    public const STATUS_UNKNOWN = 'unknown';
    /** The token existed and matched the user, but its 60s TTL had passed. */
    public const STATUS_EXPIRED = 'expired';

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
     * @return string one of the STATUS_* constants
     */
    public function consume(int $userId, string $token): string
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
            return self::STATUS_UNKNOWN;
        }

        return strtotime($row['expires']) > time() ? self::STATUS_CONSUMED : self::STATUS_EXPIRED;
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
