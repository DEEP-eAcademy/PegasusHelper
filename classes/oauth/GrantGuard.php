<?php

namespace SRAG\PegasusHelper\oauth;

/**
 * Class GrantGuard
 *
 * The single place that decides whether a {@see Grant} -- i.e. an app login,
 * and everything derived from it (access tokens, refresh tokens, SSO
 * auth-tokens, and the browser session an SSO token created) -- is still
 * valid. Used by {@see TokenService} (access/refresh tokens),
 * {@see \SRAG\PegasusHelper\authentication\AuthTokenRepository} (SSO tokens)
 * and {@see \SRAG\PegasusHelper\handler\SessionGuard\v1\SessionGuardImpl}
 * (browser sessions), so all four surfaces enforce identical rules.
 *
 * Three independent checks, in order from cheapest/most-common to
 * least-common:
 *  1. Revocation cutoff (user-specific or global) compared against the
 *     grant's *authTime*, not against a token's own issued-at time -- this is
 *     what closes the refresh-vs-revoke race (SEC-06): a successor token
 *     minted after a cutoff was set, but from a grant whose original login
 *     predates the cutoff, is still caught.
 *  2. Family revocation, e.g. after a detected refresh-token replay (SEC-03).
 *     Skipped for a pre-family grant (familyId === null).
 *  3. An optional maximum login age, if the admin has configured one.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class GrantGuard
{
    public const REASON_REVOKED = 'revoked';
    public const REASON_FAMILY_REVOKED = 'family_revoked';
    public const REASON_MAX_AGE_EXCEEDED = 'max_age_exceeded';

    /**
     * @var RevocationRepository
     */
    private $revocations;

    /**
     * @var GrantFamilyRepository
     */
    private $families;

    /**
     * @var ApiSettings
     */
    private $settings;

    public function __construct(RevocationRepository $revocations, GrantFamilyRepository $families, ApiSettings $settings)
    {
        $this->revocations = $revocations;
        $this->families = $families;
        $this->settings = $settings;
    }

    /**
     * @param Grant $grant
     * @return string|null one of the REASON_* constants if the grant is no
     *                      longer valid, or null if it checks out
     */
    public function check(Grant $grant): ?string
    {
        if ($this->revocations->isRevoked($grant->getUserId(), $grant->getAuthTime())) {
            return self::REASON_REVOKED;
        }

        $familyId = $grant->getFamilyId();
        if ($familyId !== null) {
            $family = $this->families->find($familyId);
            if ($family !== null && ((int) $family['revoked']) === 1) {
                return self::REASON_FAMILY_REVOKED;
            }
        }

        $maxAgeDays = $this->settings->getMaxLoginAgeDays();
        if ($maxAgeDays > 0 && $grant->getAuthTime() > 0) {
            $maxAgeSeconds = $maxAgeDays * 86400;
            if ($grant->getAuthTime() + $maxAgeSeconds < time()) {
                return self::REASON_MAX_AGE_EXCEEDED;
            }
        }

        return null;
    }
}
