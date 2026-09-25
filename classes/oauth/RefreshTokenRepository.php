<?php

namespace SRAG\PegasusHelper\oauth;

use ilDBInterface;

/**
 * Class RefreshTokenRepository
 *
 * Tracks issued refresh tokens in `ui_uihk_peg_refresh`, replacing the REST
 * plugin's `ui_uihk_rest_refresh` table. Access tokens are stateless and are not
 * tracked here; only refresh tokens need a server-side row, because a refresh
 * token must remain valid until it expires even after being used (see
 * {@see TokenService::refresh()}), and because the Statistics tab counts refreshes
 * by their `created` date.
 *
 * `token_hash` is the primary key directly (no surrogate id/sequence): it is
 * already a unique 64-char sha256 hex digest, so a separate auto-increment
 * column would only exist to satisfy a "tables need a numeric id" habit.
 *
 * Since 7.3.0 a row also tracks `family_id` (the login it belongs to, see
 * {@see Grant}/{@see GrantFamilyRepository}), `expires` (a plain unix time,
 * used to purge stale rows without needing a token's own signature) and
 * `rotated_at` (0 until this refresh token has been used to mint a successor;
 * see {@see claimRotation()}). A row created before 7.3.0 has `family_id =
 * NULL` and `expires = 0`; {@see TokenService::refresh()} adopts such a row
 * into a family on its first refresh under the new scheme, and
 * {@see purgeExpired()} falls back to `created` + the refresh TTL for it.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class RefreshTokenRepository
{
    private const TABLE = 'ui_uihk_peg_refresh';

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Records a newly issued refresh token.
     *
     * @param string      $tokenString the serialized refresh token
     * @param int         $userId
     * @param string|null $familyId    the login this token belongs to, or null
     *                                 for a pre-family token (never the case
     *                                 for a token minted by 7.3.0+)
     * @param int         $expiresAt   unix time, matching the token's own `ttl` field
     */
    public function insert(string $tokenString, int $userId, ?string $familyId, int $expiresAt): void
    {
        $hash = $this->hash($tokenString);
        $now = date('Y-m-d H:i:s');

        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (token_hash, user_id, family_id, expires, rotated_at, created, last_refresh, refreshes) '
            . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
            ['text', 'integer', 'text', 'integer', 'integer', 'timestamp', 'timestamp', 'integer'],
            [$hash, $userId, $familyId, $expiresAt, 0, $now, $now, 0]
        );
    }

    /**
     * @param string $tokenString the serialized refresh token
     * @return array{token_hash:string,user_id:int,family_id:?string,expires:int,rotated_at:int,created:string,last_refresh:string,refreshes:int}|null
     */
    public function find(string $tokenString): ?array
    {
        $set = $this->db->queryF(
            'SELECT token_hash, user_id, family_id, expires, rotated_at, created, last_refresh, refreshes '
            . 'FROM ' . self::TABLE . ' WHERE token_hash = %s',
            ['text'],
            [$this->hash($tokenString)]
        );

        return $this->db->fetchAssoc($set);
    }

    /**
     * @param string $tokenString the serialized refresh token
     * @return bool true if a row for this refresh token exists (i.e. it has not been
     *              explicitly revoked/removed; expiry itself is checked separately via
     *              the token's own ttl field)
     */
    public function exists(string $tokenString): bool
    {
        $set = $this->db->queryF(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE token_hash = %s',
            ['text'],
            [$this->hash($tokenString)]
        );

        return $this->db->fetchAssoc($set) !== null;
    }

    /**
     * Atomically claims this refresh token's *one* rotation: flips
     * `rotated_at` from 0 to now, but only if it was still 0. Concurrent
     * callers racing the same token all issue this UPDATE; exactly one of
     * them gets an affected-row count of 1 (see {@see TokenService::refresh()},
     * which uses that to decide whether this call is the legitimate first use,
     * a bounded-grace-window retry, or a replay).
     *
     * @param string $tokenString the serialized refresh token
     * @return int the number of affected rows (0 or 1)
     */
    public function claimRotation(string $tokenString): int
    {
        return (int) $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET rotated_at = %s, last_refresh = %s, refreshes = refreshes + 1 '
            . 'WHERE token_hash = %s AND rotated_at = 0',
            ['integer', 'timestamp', 'text'],
            [time(), date('Y-m-d H:i:s'), $this->hash($tokenString)]
        );
    }

    /**
     * Adopts a legacy (pre-7.3.0, family_id IS NULL) row into a family on its
     * first refresh under the new scheme. A no-op if the row already has a
     * family (e.g. a concurrent refresh of the same legacy row won the race).
     *
     * @param string $tokenString the serialized refresh token
     * @param string $familyId
     */
    public function attachFamily(string $tokenString, string $familyId): void
    {
        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET family_id = %s WHERE token_hash = %s AND family_id IS NULL',
            ['text', 'text'],
            [$familyId, $this->hash($tokenString)]
        );
    }

    /**
     * Marks a refresh token as used again: bumps `last_refresh` and the `refreshes` counter.
     * Kept for the (unbounded, pre-7.3.0) migration path; new code should use
     * {@see claimRotation()} instead, which is atomic.
     *
     * @param string $tokenString the serialized refresh token
     */
    public function touch(string $tokenString): void
    {
        $hash = $this->hash($tokenString);

        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET last_refresh = %s, refreshes = refreshes + 1 WHERE token_hash = %s',
            ['timestamp', 'text'],
            [date('Y-m-d H:i:s'), $hash]
        );
    }

    /**
     * Inserts a migrated refresh token row directly (used by {@see \SRAG\PegasusHelper\migration\RestPluginMigration}).
     * Migrated rows have no family (`family_id = NULL`, `expires = 0`); they
     * are adopted into a family on their first refresh, exactly like any other
     * pre-7.3.0 row (see {@see attachFamily()}).
     *
     * @param string $tokenString
     * @param int    $userId
     * @param string $created      'Y-m-d H:i:s'
     * @param string $lastRefresh  'Y-m-d H:i:s'
     * @param int    $refreshes
     */
    public function insertMigrated(string $tokenString, int $userId, string $created, string $lastRefresh, int $refreshes): void
    {
        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (token_hash, user_id, family_id, expires, rotated_at, created, last_refresh, refreshes) '
            . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
            ['text', 'integer', 'text', 'integer', 'integer', 'timestamp', 'timestamp', 'integer'],
            [$this->hash($tokenString), $userId, null, 0, 0, $created, $lastRefresh, $refreshes]
        );
    }

    /**
     * Deletes rows that are definitely no longer usable: new-style rows past
     * their own `expires` time, and legacy rows (expires = 0) older than
     * $legacyTtlSeconds. Called opportunistically -- see
     * {@see TokenService::refresh()} -- rather than on every request, since
     * this is routine housekeeping rather than a security control.
     *
     * @param int $legacyTtlSeconds the current refresh-token TTL setting, used
     *                              as a fallback age limit for rows minted
     *                              before the `expires` column existed
     */
    public function purgeExpired(int $legacyTtlSeconds): void
    {
        $now = time();

        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE expires > 0 AND expires < %s',
            ['integer'],
            [$now]
        );

        $cutoff = date('Y-m-d H:i:s', $now - max(0, $legacyTtlSeconds));
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE expires = 0 AND created < %s',
            ['timestamp'],
            [$cutoff]
        );
    }

    /**
     * @param int $withinDays
     * @return int number of refreshes recorded within the last $withinDays days
     */
    public function countCreatedWithinDays(int $withinDays): int
    {
        $set = $this->db->queryF(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE . ' WHERE DATEDIFF(%s, created) < %s',
            ['timestamp', 'integer'],
            [date('Y-m-d H:i:s'), $withinDays]
        );
        $row = $this->db->fetchAssoc($set);

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    private function hash(string $tokenString): string
    {
        return hash('sha256', $tokenString);
    }
}
