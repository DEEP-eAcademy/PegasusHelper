<?php

namespace SRAG\PegasusHelper\authentication;

use SRAG\PegasusHelper\oauth\Grant;

/**
 * Class AuthTokenConsumption
 *
 * The result of {@see AuthTokenRepository::consume()}: a status plus, on
 * success, the {@see Grant} the SSO auth-token was minted from. Carrying the
 * grant (rather than just a user id) is what lets a caller apply
 * {@see \SRAG\PegasusHelper\oauth\GrantGuard} to a redeemed SSO token, so a
 * one-time token minted from a login that gets revoked *after* the token was
 * minted but *before* it is redeemed is still rejected (SEC-02).
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class AuthTokenConsumption
{
    /**
     * @var string one of AuthTokenRepository::STATUS_*
     */
    private $status;

    /**
     * @var Grant|null non-null only when status is STATUS_CONSUMED
     */
    private $grant;

    public function __construct(string $status, ?Grant $grant = null)
    {
        $this->status = $status;
        $this->grant = $grant;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getGrant(): ?Grant
    {
        return $this->grant;
    }

    public function isConsumed(): bool
    {
        return $this->status === AuthTokenRepository::STATUS_CONSUMED;
    }
}
