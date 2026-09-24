<?php

namespace SRAG\PegasusHelper\oauth;

use ilDBInterface;

/**
 * Class RevocationRepository
 *
 * Access tokens are stateless by design (see {@see TokenService}'s docblock),
 * which is exactly what makes them impossible to kill individually. This
 * repository gives an admin a way to invalidate every token already issued --
 * to one user, or to everyone -- without touching the signing salt: it records
 * a "revoked before" cutoff in `ui_uihk_pegasus_revocation`, checked against a
 * token's issued-at time (see {@see TokenCodec::issuedAt()}) on every request.
 * The global cutoff is stored under the reserved user id 0.
 *
 * A token minted before this feature existed (from the REST plugin, or from an
 * older PegasusHelper) has no issued-at time and is treated as issued at time
 * zero -- i.e. it is revoked by any cutoff at all, forcing a fresh login. This
 * is deliberate: it is the safe default once revocation is used even once.
 *
 * Both the cutoff and a token's issued-at time have 1-second resolution, and
 * ties resolve in favour of revocation (see {@see isRevoked()}). In the rare
 * case a login is issued in the exact same wall-clock second as a revoke
 * action, that login may itself come back revoked and need one retry -- an
 * acceptable cost for not having a revoked token ever slip through instead.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class RevocationRepository
{
    private const TABLE = 'ui_uihk_pegasus_revocation';
    private const GLOBAL_ID = 0;

    /**
     * @var ilDBInterface
     */
    private $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Invalidates every token issued to this user up to and including this moment.
     * A token minted after this call (e.g. from a fresh login started a moment
     * later) is unaffected.
     *
     * @param int $userId
     */
    public function revokeUser(int $userId): void
    {
        $this->setCutoff($userId, time());
    }

    /**
     * Invalidates every token issued to every user (a blunter, global version of
     * {@see revokeUser()}), for incident response.
     */
    public function revokeAll(): void
    {
        $this->setCutoff(self::GLOBAL_ID, time());
    }

    /**
     * @param int $userId
     * @param int $issuedAt as returned by {@see TokenCodec::issuedAt()}
     * @return bool true if a token issued at this time for this user has been
     *              revoked, either individually or by a global revocation
     */
    public function isRevoked(int $userId, int $issuedAt): bool
    {
        $userCutoff = $this->getCutoff($userId);
        $globalCutoff = $userId === self::GLOBAL_ID ? $userCutoff : $this->getCutoff(self::GLOBAL_ID);
        $cutoff = max($userCutoff, $globalCutoff);

        // Both timestamps have 1-second resolution, so a token minted in the same
        // wall-clock second as a revoke action would otherwise tie. Resolve ties
        // in favour of revocation (<=, not <): the rare cost is that a login
        // started in that same second may occasionally have to retry, which is
        // far preferable to a revoked token being accepted as still valid.
        return $cutoff > 0 && $issuedAt <= $cutoff;
    }

    /**
     * @param int $userId
     * @return int the unix time before which this user's tokens are individually
     *             revoked, or 0 if nothing has been revoked for them specifically
     */
    public function getCutoff(int $userId): int
    {
        $set = $this->db->queryF(
            'SELECT revoked_before FROM ' . self::TABLE . ' WHERE user_id = %s',
            ['integer'],
            [$userId]
        );
        $row = $this->db->fetchAssoc($set);

        return $row !== null ? (int) $row['revoked_before'] : 0;
    }

    private function setCutoff(int $userId, int $cutoff): void
    {
        $this->db->replace(
            self::TABLE,
            ['user_id' => ['integer', $userId]],
            ['revoked_before' => ['integer', $cutoff]]
        );
    }
}
