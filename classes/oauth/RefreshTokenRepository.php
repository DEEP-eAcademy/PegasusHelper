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
     * @param string $tokenString the serialized refresh token
     * @param int    $userId
     */
    public function insert(string $tokenString, int $userId): void
    {
        $hash = $this->hash($tokenString);
        $now = date('Y-m-d H:i:s');

        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (token_hash, user_id, created, last_refresh, refreshes) '
            . 'VALUES (%s, %s, %s, %s, %s)',
            ['text', 'integer', 'timestamp', 'timestamp', 'integer'],
            [$hash, $userId, $now, $now, 0]
        );
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
     * Marks a refresh token as used again: bumps `last_refresh` and the `refreshes` counter.
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
            'INSERT INTO ' . self::TABLE . ' (token_hash, user_id, created, last_refresh, refreshes) '
            . 'VALUES (%s, %s, %s, %s, %s)',
            ['text', 'integer', 'timestamp', 'timestamp', 'integer'],
            [$this->hash($tokenString), $userId, $created, $lastRefresh, $refreshes]
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
