<?php

namespace SRAG\PegasusHelper\oauth;

use ilDBInterface;

/**
 * Class GrantFamilyRepository
 *
 * Repository over `ui_uihk_peg_family`: one row per app login (see {@see Grant}),
 * tracking whether that login's whole family of access/refresh tokens has been
 * revoked. This backs replay detection on refresh (see
 * {@see TokenService::refresh()}, SEC-03/SEC-06) and lets an admin's "revoke
 * user"/"revoke all" actions reach every family, not just the plain cutoff (see
 * {@see \SRAG\PegasusHelper\audit\AuditLog::EVENT_ADMIN_TOKENS_REVOKED}).
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class GrantFamilyRepository
{
    private const TABLE = 'ui_uihk_peg_family';

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Records a new family. Ignored (not an error) if the id already exists --
     * this can legitimately happen when a legacy refresh-token row is adopted
     * into a family lazily and two concurrent refreshes of that same legacy
     * row both try to create it (see {@see TokenService::refresh()}).
     */
    public function create(string $familyId, int $userId, int $authTime): void
    {
        if ($this->find($familyId) !== null) {
            return;
        }

        $now = time();
        $this->db->manipulateF(
            'INSERT INTO ' . self::TABLE . ' (family_id, user_id, auth_time, created, last_used, revoked) '
            . 'VALUES (%s, %s, %s, %s, %s, %s)',
            ['text', 'integer', 'integer', 'integer', 'integer', 'integer'],
            [$familyId, $userId, $authTime, $now, $now, 0]
        );
    }

    /**
     * @return array{family_id:string,user_id:int,auth_time:int,revoked:int}|null
     */
    public function find(string $familyId): ?array
    {
        $set = $this->db->queryF(
            'SELECT family_id, user_id, auth_time, revoked FROM ' . self::TABLE . ' WHERE family_id = %s',
            ['text'],
            [$familyId]
        );

        return $this->db->fetchAssoc($set);
    }

    public function touch(string $familyId): void
    {
        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET last_used = %s WHERE family_id = %s',
            ['integer', 'text'],
            [time(), $familyId]
        );
    }

    /**
     * Marks one family as revoked, e.g. after a detected refresh-token replay
     * (see {@see TokenService::refresh()}) -- every access token still bearing
     * this family is then rejected by {@see GrantGuard::check()} without
     * having to wait for its own expiry.
     */
    public function revoke(string $familyId): void
    {
        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET revoked = 1 WHERE family_id = %s',
            ['text'],
            [$familyId]
        );
    }

    /**
     * Revokes every family belonging to a user, e.g. from the admin "Revoke"
     * action -- in addition to (not instead of) the plain cutoff in
     * {@see RevocationRepository}, since a legacy token has no family at all.
     */
    public function revokeByUser(int $userId): void
    {
        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET revoked = 1 WHERE user_id = %s',
            ['integer'],
            [$userId]
        );
    }

    /**
     * Revokes every family, e.g. from "Revoke all" / a salt rotation.
     */
    public function revokeAll(): void
    {
        $this->db->manipulate('UPDATE ' . self::TABLE . ' SET revoked = 1');
    }

    /**
     * Deletes families that have not been used in a long time (well past any
     * plausible refresh-token TTL), to keep the table from growing without
     * bound. Called opportunistically from {@see TokenService::refresh()}.
     */
    public function purgeStale(int $olderThanSeconds): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE last_used < %s',
            ['integer'],
            [time() - $olderThanSeconds]
        );
    }
}
