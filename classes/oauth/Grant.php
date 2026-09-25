<?php

namespace SRAG\PegasusHelper\oauth;

/**
 * Class Grant
 *
 * Represents one app login. Every access token, refresh token and SSO
 * auth-token derived from that login carries the same grant, so that
 * revoking the login also revokes everything derived from it -- even a
 * successor minted by a later token refresh (see {@see TokenService::refresh()}
 * and {@see GrantGuard}).
 *
 * `authTime` is the unix time of the *original* login, not of whichever token
 * happens to carry it: a refreshed token inherits its parent's `authTime`
 * rather than stamping "now", which is what lets a revocation cutoff compared
 * against `authTime` (see {@see GrantGuard::check()}) catch a successor token
 * minted after the cutoff was set but from a grant that existed before it --
 * closing the refresh-vs-revoke race a plain "issued-at" comparison would miss.
 *
 * `familyId` groups every access/refresh token minted from one login, so that
 * a single detected refresh-token replay (see {@see TokenService::refresh()})
 * can invalidate the whole family in one step, without waiting for the
 * signing-salt-wide "revoke all" hammer. It is null for a token that predates
 * this feature (see {@see TokenCodec::grantFromMisc()}); such a token is still
 * covered by the plain user/global revocation cutoff, just not by family
 * revocation.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class Grant
{
    /**
     * @var int
     */
    private $userId;

    /**
     * @var int unix time of the original login; 0 for a pre-grant token (see
     *          {@see TokenCodec::issuedAt()}), which is always older than any
     *          revocation cutoff
     */
    private $authTime;

    /**
     * @var string|null 32 lowercase hex characters, or null for a pre-family token
     */
    private $familyId;

    public function __construct(int $userId, int $authTime, ?string $familyId)
    {
        $this->userId = $userId;
        $this->authTime = $authTime;
        $this->familyId = $familyId;
    }

    /**
     * A brand-new grant for a fresh login: authTime is now, and a new random family id is minted.
     */
    public static function fresh(int $userId): self
    {
        return new self($userId, time(), bin2hex(random_bytes(16)));
    }

    /**
     * A successor grant for a token refresh: keeps the same userId, authTime
     * and familyId as the token being refreshed, so it is caught by exactly
     * the same revocation checks as its parent.
     */
    public function successor(): self
    {
        return new self($this->userId, $this->authTime, $this->familyId);
    }

    /**
     * Same identity, but attached to a (possibly newly created) family. Used
     * when a legacy refresh-token row (minted before families existed) is
     * adopted into one on its first refresh under the new scheme.
     */
    public function withFamily(string $familyId): self
    {
        return new self($this->userId, $this->authTime, $familyId);
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getAuthTime(): int
    {
        return $this->authTime;
    }

    public function getFamilyId(): ?string
    {
        return $this->familyId;
    }
}
