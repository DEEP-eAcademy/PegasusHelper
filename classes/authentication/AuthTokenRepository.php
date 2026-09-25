<?php

namespace SRAG\PegasusHelper\authentication;

use ilDBInterface;
use SRAG\PegasusHelper\oauth\Grant;
use SRAG\PegasusHelper\oauth\GrantGuard;

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
 * The token itself is only ever stored as a sha256 hash (never raw), and since
 * 7.3.0 each row also carries the {@see Grant} it was minted from
 * (`auth_time`/`family_id`), so {@see consume()} can reject a token whose login
 * was revoked *after* the token was minted but before it was redeemed (SEC-02).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class AuthTokenRepository
{
    private const TABLE = 'ui_uihk_peg_token';
    private const TTL_SECONDS = 60;

    /** The token existed, matched the user, had not expired, and its grant was not revoked. */
    public const STATUS_CONSUMED = 'consumed';
    /** The token never existed, was already used, or belonged to another user -- these are indistinguishable by design (the row is looked up by token+user together). */
    public const STATUS_UNKNOWN = 'unknown';
    /** The token existed and matched the user, but its 60s TTL had passed. */
    public const STATUS_EXPIRED = 'expired';
    /** The token was valid, but the login it was minted from has since been revoked (user/global cutoff, family revocation, or the max login age). */
    public const STATUS_REVOKED = 'revoked';

    /**
     * @var ilDBInterface
     */
    private $db;

    /**
     * @var GrantGuard
     */
    private $guard;

    public function __construct(ilDBInterface $db, GrantGuard $guard)
    {
        $this->db = $db;
        $this->guard = $guard;
    }

    /**
     * Creates a new one-time token for the given login, valid for 60 seconds.
     *
     * @param Grant $grant the login this token is minted from
     * @return string the raw token (only its hash is stored)
     */
    public function create(Grant $grant): string
    {
        $this->purgeExpired();

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (token, user_id, expires, auth_time, family_id) VALUES (%s, %s, %s, %s, %s)',
            ['text', 'integer', 'timestamp', 'integer', 'text'],
            [$this->hash($token), $grant->getUserId(), $expires, $grant->getAuthTime(), $grant->getFamilyId()]
        );

        return $token;
    }

    /**
     * Atomically validates and deletes a token. Unlike a plain "SELECT then
     * DELETE", the delete itself carries the validity condition
     * (unexpired, matching user), so when two requests race the very same
     * token, the affected-row count lets exactly one of them win -- the other
     * always gets a non-consumed status, never a second CONSUMED (SEC-05).
     *
     * @param int    $userId
     * @param string $token
     * @return AuthTokenConsumption
     */
    public function consume(int $userId, string $token): AuthTokenConsumption
    {
        $hash = $this->hash($token);
        $now = date('Y-m-d H:i:s');

        // The SELECT and the DELETE both filter on the *compound* (token,
        // user_id) key throughout: a token presented with the wrong claimed
        // user id must simply miss (STATUS_UNKNOWN) and touch nothing. An
        // earlier version of this method additionally deleted any leftover
        // row by token hash *alone* once the compound lookup failed, which
        // meant a wrong-user guess against someone else's still-valid token
        // would silently destroy that legitimate owner's token -- a
        // self-inflicted denial of service. purgeExpired() (run from
        // create()) is what actually sweeps stale rows; this method never
        // needs to.
        $set = $this->db->queryF(
            'SELECT expires, auth_time, family_id FROM ' . self::TABLE . ' WHERE token = %s AND user_id = %s',
            ['text', 'integer'],
            [$hash, $userId]
        );
        $row = $this->db->fetchAssoc($set);

        if ($row === null) {
            return new AuthTokenConsumption(self::STATUS_UNKNOWN);
        }

        $deleted = (int) $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE token = %s AND user_id = %s AND expires > %s',
            ['text', 'integer', 'timestamp'],
            [$hash, $userId, $now]
        );

        if ($deleted !== 1) {
            // Either this row had already expired by the time the DELETE ran
            // (a narrow race against its own 60s TTL), or a concurrent
            // request already won the race to consume it first -- either way,
            // this call did not consume it. $row's own snapshot (taken a
            // moment earlier) is enough to tell the two apart: if it looked
            // unexpired then, a concurrent winner is the more likely
            // explanation than expiring in that same instant.
            $status = strtotime((string) $row['expires']) > time() ? self::STATUS_UNKNOWN : self::STATUS_EXPIRED;

            return new AuthTokenConsumption($status);
        }

        $grant = new Grant(
            $userId,
            (int) $row['auth_time'],
            $row['family_id'] !== null ? (string) $row['family_id'] : null
        );

        if ($this->guard->check($grant) !== null) {
            return new AuthTokenConsumption(self::STATUS_REVOKED);
        }

        return new AuthTokenConsumption(self::STATUS_CONSUMED, $grant);
    }

    /**
     * Deletes every outstanding SSO token for a user, e.g. from the admin
     * "Revoke" action (see {@see \SRAG\PegasusHelper\oauth\TokenService::revokeUser()}).
     */
    public function deleteByUser(int $userId): void
    {
        $this->db->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE user_id = %s', ['integer'], [$userId]);
    }

    /**
     * Deletes every outstanding SSO token, e.g. from "Revoke all" or a salt rotation.
     */
    public function deleteAll(): void
    {
        $this->db->manipulate('DELETE FROM ' . self::TABLE);
    }

    private function purgeExpired(): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE expires < %s',
            ['timestamp'],
            [date('Y-m-d H:i:s')]
        );
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
