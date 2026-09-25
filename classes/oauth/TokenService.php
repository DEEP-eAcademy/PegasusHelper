<?php

namespace SRAG\PegasusHelper\oauth;

use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\audit\AuditLog;
use SRAG\PegasusHelper\authentication\AuthTokenRepository;

/**
 * Class TokenService
 *
 * Issues and validates the OAuth2 access/refresh token pair used by the Pegasus
 * app, replacing the ILIAS REST plugin's `core/oauth2_v2` for the single
 * `ilias_pegasus` API client this plugin serves.
 *
 * Access tokens are otherwise stateless: any token whose signature and expiry
 * check out is accepted, exactly as the REST plugin behaved (its
 * `ui_uihk_rest_access` table was written to but never actually consulted to
 * reject a token early). The two exceptions are {@see GrantGuard} (an admin can
 * invalidate every already-issued token for a user, or globally, or for one
 * compromised login family, without waiting for expiry or touching the signing
 * salt) and the login's own {@see Grant} identity (see below).
 *
 * Since 7.3.0, every token belongs to a {@see Grant}: one app login, carried
 * through every access/refresh token minted from it (including every
 * successor issued by {@see refresh()}) via the token's `misc` field (see
 * {@see TokenCodec}). This is what lets a revocation made *after* a refresh
 * still catch the resulting successor (SEC-06), and what lets a detected
 * refresh-token replay revoke a whole login's tokens at once (SEC-03) rather
 * than only the one token replayed.
 *
 * Refresh tokens are additionally tracked in {@see RefreshTokenRepository} and
 * rotate on every use: the app is allowed to fire several concurrent refreshes
 * with the same refresh token (see {@see self::REUSE_GRACE_SECONDS}), but a
 * refresh token presented again well after it was already rotated is treated
 * as a stolen-credential replay and kills its whole family.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class TokenService
{
    /**
     * How long after a refresh token was rotated a second presentation of it
     * is still tolerated as "the app fired concurrent refreshes" rather than
     * treated as a replay. The ILIAS-Pegasus app has no mutex around its own
     * refresh calls, so more than one request can legitimately race with the
     * very same refresh token when the access token expires; a real attacker
     * replaying a stolen, already-rotated refresh token minutes or hours later
     * is expected to fall well outside this window.
     */
    private const REUSE_GRACE_SECONDS = 60;

    /**
     * @var TokenCodec
     */
    private $codec;

    /**
     * @var ApiSettings
     */
    private $settings;

    /**
     * @var RefreshTokenRepository
     */
    private $refreshTokens;

    /**
     * @var GrantFamilyRepository
     */
    private $families;

    /**
     * @var RevocationRepository
     */
    private $revocations;

    /**
     * @var GrantGuard
     */
    private $guard;

    /**
     * @var AuthTokenRepository
     */
    private $authTokens;

    /**
     * @var AuditLog
     */
    private $audit;

    public function __construct(
        TokenCodec $codec,
        ApiSettings $settings,
        RefreshTokenRepository $refreshTokens,
        GrantFamilyRepository $families,
        RevocationRepository $revocations,
        GrantGuard $guard,
        AuthTokenRepository $authTokens,
        AuditLog $audit
    ) {
        $this->codec = $codec;
        $this->settings = $settings;
        $this->refreshTokens = $refreshTokens;
        $this->families = $families;
        $this->revocations = $revocations;
        $this->guard = $guard;
        $this->authTokens = $authTokens;
        $this->audit = $audit;
    }

    /**
     * Issues a new access/refresh token pair and records the refresh token.
     *
     * @param int|Grant $subject an int mints a brand-new login (a fresh
     *                           {@see Grant}, with a new family); a {@see Grant}
     *                           mints a successor pair that inherits its
     *                           identity -- used when refreshing, so the
     *                           successor is caught by the same revocation and
     *                           replay checks as the token it replaces
     * @return array{access_token:string,refresh_token:string,expires_in:int,token_type:string,scope:null}
     */
    public function issuePair($subject): array
    {
        $grant = $subject instanceof Grant ? $subject : Grant::fresh((int) $subject);

        $apiKey = $this->settings->getApiKey();
        $iliasClient = defined('CLIENT_ID') ? CLIENT_ID : '';

        $accessArray = $this->codec->generate($grant->getUserId(), $iliasClient, $apiKey, TokenCodec::CLASS_ACCESS, $this->settings->getAccessTokenTtlMinutes(), $grant);
        $refreshArray = $this->codec->generate($grant->getUserId(), $iliasClient, $apiKey, TokenCodec::CLASS_REFRESH, $this->settings->getRefreshTokenTtlMinutes(), $grant);

        $accessToken = $this->codec->serialize($accessArray);
        $refreshToken = $this->codec->serialize($refreshArray);

        if ($grant->getFamilyId() !== null) {
            $this->families->create($grant->getFamilyId(), $grant->getUserId(), $grant->getAuthTime());
        }

        // Store the *normalized* form, matching what refresh() looks up: serialize()
        // urlencodes its base64 output, which routinely contains '+', '/' or '='
        // and thus gets percent-escaped; normalize()'s rawurldecode() then differs
        // from the raw serialized string whenever that happened. Hashing the raw
        // form here would make the very first refresh attempt fail unpredictably
        // (whenever escaping occurred), rejecting the token as "revoked".
        $this->refreshTokens->insert(
            $this->codec->normalize($refreshToken),
            $grant->getUserId(),
            $grant->getFamilyId(),
            (int) $refreshArray['ttl']
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int) $accessArray['ttl'] - time(),
            'token_type' => 'bearer',
            'scope' => null,
        ];
    }

    /**
     * Validates a Bearer access token.
     *
     * @param string $rawToken as received (still urlencoded)
     * @return int the authenticated ILIAS user id
     *
     * @throws ApiException 401 if the token is missing, malformed, expired or does not match this client
     */
    public function validateAccess(?string $rawToken): int
    {
        return $this->validateAccessGrant($rawToken)->getUserId();
    }

    /**
     * Same as {@see validateAccess()}, but returns the token's full
     * {@see Grant} rather than just the user id -- used where a
     * derived credential (e.g. an SSO auth-token, see
     * {@see \SRAG\PegasusHelper\api\controller\AuthTokenController}) needs to
     * inherit the same login identity, so it stays subject to the same
     * revocation/family checks.
     *
     * @throws ApiException 401 if the token is missing, malformed, expired or does not match this client
     */
    public function validateAccessGrant(?string $rawToken): Grant
    {
        if ($rawToken === null || $rawToken === '') {
            throw ApiException::unauthorized('Missing access token')->withReason('missing_token');
        }

        $token = $this->decodeAndCheck($rawToken, TokenCodec::CLASS_ACCESS);

        return TokenCodec::grantOf($token);
    }

    /**
     * Handles the `grant_type=refresh_token` flow of `POST /v2/oauth2/token`.
     *
     * @param string|null   $apiKey
     * @param string|null   $apiSecret
     * @param string|null   $refreshToken
     * @param callable|null $checkEligibility optional `fn(int $userId): void`,
     *                      called after the refresh token itself has been
     *                      validated but before a successor is minted; it
     *                      should throw an {@see ApiException} (401) if the
     *                      user is no longer eligible to be logged in (e.g.
     *                      deactivated, deleted or time-limited-out). Kept as
     *                      an injected callback rather than a hard dependency
     *                      so this class does not need to know how to boot
     *                      ILIAS -- see {@see \SRAG\PegasusHelper\api\controller\TokenController}.
     * @return array{access_token:string,refresh_token:string,expires_in:int,token_type:string,scope:null}
     *
     * @throws ApiException 400 on a malformed request, 401 on bad credentials,
     *                      an invalid/expired/revoked/replayed refresh token,
     *                      or an ineligible user
     */
    public function refresh(?string $apiKey, ?string $apiSecret, ?string $refreshToken, ?callable $checkEligibility = null): array
    {
        if ($refreshToken === null || $refreshToken === '') {
            throw ApiException::badRequest('Missing refresh_token')->withReason('missing_refresh_token');
        }
        if ($apiKey === null || $apiKey === '') {
            throw ApiException::badRequest('Missing api_key')->withReason('missing_api_key');
        }

        // Fail closed if the client credentials aren't configured at all,
        // rather than letting hash_equals('', '') accept a request that omits
        // api_secret entirely (SEC-01).
        $expectedKey = $this->settings->requireApiKey();
        $expectedSecret = $this->settings->requireApiSecret();

        if (!hash_equals($expectedKey, $apiKey)) {
            throw ApiException::unauthorized('Unknown client')->withReason('unknown_client');
        }
        if (!hash_equals($expectedSecret, (string) $apiSecret)) {
            throw ApiException::unauthorized('Invalid client secret')->withReason('invalid_client_secret');
        }

        $token = $this->decodeAndCheck($refreshToken, TokenCodec::CLASS_REFRESH);
        $normalized = $this->codec->normalize($refreshToken);
        $grant = TokenCodec::grantOf($token);
        $userId = $grant->getUserId();

        $row = $this->refreshTokens->find($normalized);
        if ($row === null) {
            throw ApiException::unauthorized('Refresh token has been revoked')->withReason('refresh_token_unknown');
        }

        if ($grant->getFamilyId() === null) {
            $grant = $this->adoptLegacyFamily($grant, $normalized, (string) $row['created']);
        }

        if ($checkEligibility !== null) {
            $checkEligibility($userId);
        }

        $this->audit->setActor($userId);

        if ($this->refreshTokens->claimRotation($normalized) > 0) {
            return $this->issueSuccessor($grant, $normalized, false);
        }

        $current = $this->refreshTokens->find($normalized);
        $rotatedAt = $current !== null ? (int) $current['rotated_at'] : 0;

        if ($rotatedAt > 0 && (time() - $rotatedAt) <= self::REUSE_GRACE_SECONDS) {
            // Within the bounded grace window: most likely the app firing
            // concurrent refreshes with the same token, or retrying after a
            // lost response. Issue another sibling in the same family rather
            // than rejecting a legitimate client.
            return $this->issueSuccessor($grant, $normalized, true);
        }

        // Outside the grace window, this refresh token was already rotated a
        // while ago and is being presented again: a genuine replay. Kill the
        // whole family so every token minted from this login -- including any
        // successor already issued -- stops working immediately, rather than
        // only this one refresh token.
        $familyId = $grant->getFamilyId();
        if ($familyId !== null) {
            $this->families->revoke($familyId);
        }
        $this->audit->log(AuditLog::EVENT_TOKEN_REPLAY, AuditLog::LEVEL_WARNING, [
            'refresh_fp' => AuditLog::fingerprint($normalized),
            'rotated_at' => $rotatedAt,
        ]);

        throw ApiException::unauthorized('Refresh token has been revoked')->withReason('refresh_replay');
    }

    /**
     * Invalidates every token, SSO auth-token and login family already issued
     * to this user. Does not affect a fresh login started after this call.
     *
     * @param int $userId
     */
    public function revokeUser(int $userId): void
    {
        $this->revocations->revokeUser($userId);
        $this->families->revokeByUser($userId);
        $this->authTokens->deleteByUser($userId);
    }

    /**
     * Invalidates every token, SSO auth-token and login family already issued
     * to every user. For incident response (e.g. alongside a signing-salt
     * rotation).
     */
    public function revokeAll(): void
    {
        $this->revocations->revokeAll();
        $this->families->revokeAll();
        $this->authTokens->deleteAll();
    }

    /**
     * Adopts a pre-7.3.0 refresh-token row (no family yet) into a new family,
     * using the *token's own* issued-at time as the login's authTime when
     * available (a token minted by the REST plugin, with an empty `misc`, has
     * none -- fall back to the row's own `created` timestamp, which was
     * stamped at the same moment by {@see RefreshTokenRepository::insertMigrated()}).
     */
    private function adoptLegacyFamily(Grant $grant, string $normalized, string $rowCreated): Grant
    {
        $authTime = $grant->getAuthTime() > 0 ? $grant->getAuthTime() : (int) strtotime($rowCreated);
        $familyId = bin2hex(random_bytes(16));

        $this->families->create($familyId, $grant->getUserId(), $authTime);
        $this->refreshTokens->attachFamily($normalized, $familyId);

        // A concurrent refresh of the very same legacy row may have won the
        // race to attach a *different* family id; defer to whatever actually
        // ended up on the row so both requests agree on one family.
        $row = $this->refreshTokens->find($normalized);
        $attachedFamilyId = ($row['family_id'] ?? null) !== null ? (string) $row['family_id'] : $familyId;

        return new Grant($grant->getUserId(), $authTime, $attachedFamilyId);
    }

    private function issueSuccessor(Grant $grant, string $normalizedOldToken, bool $withinGraceWindow): array
    {
        $pair = $this->issuePair($grant->successor());

        $fields = [
            'refresh_fp' => AuditLog::fingerprint($normalizedOldToken),
            'new_refresh_fp' => AuditLog::fingerprint($this->codec->normalize($pair['refresh_token'])),
        ];
        if ($withinGraceWindow) {
            $fields['grace_window'] = true;
        }
        $this->audit->log(AuditLog::EVENT_TOKEN_REFRESH, AuditLog::LEVEL_INFO, $fields);

        // Routine housekeeping, not a security control -- run it occasionally
        // rather than on every refresh.
        if (random_int(1, 100) === 1) {
            $this->refreshTokens->purgeExpired($this->settings->getRefreshTokenTtlMinutes() * 60);
            $this->families->purgeStale(max($this->settings->getRefreshTokenTtlMinutes() * 60, 86400) * 2);
        }

        return $pair;
    }

    /**
     * @param string $rawToken
     * @param string $expectedClass one of TokenCodec::CLASS_*
     * @return array the decoded, verified token array
     *
     * @throws ApiException 401 on any failure
     */
    private function decodeAndCheck(string $rawToken, string $expectedClass): array
    {
        $normalized = $this->codec->normalize($rawToken);
        $token = $this->codec->deserialize($normalized);

        if ($token === null) {
            throw ApiException::unauthorized('Invalid token')->withReason('malformed');
        }
        if (!$this->codec->isValid($token)) {
            throw ApiException::unauthorized('Invalid token')->withReason('invalid_signature');
        }
        if ($token['class'] !== $expectedClass) {
            throw ApiException::unauthorized('Invalid token')->withReason('wrong_token_class');
        }
        if ($this->codec->isExpired($token)) {
            throw ApiException::unauthorized('Token has expired')->withReason('expired');
        }
        if (!hash_equals($this->settings->getApiKey(), (string) $token['api_key'])) {
            throw ApiException::unauthorized('Invalid token')->withReason('api_key_mismatch');
        }
        $iliasClient = defined('CLIENT_ID') ? CLIENT_ID : '';
        if (!hash_equals($iliasClient, (string) $token['ilias_client'])) {
            throw ApiException::unauthorized('Invalid token')->withReason('client_mismatch');
        }

        $reason = $this->guard->check(TokenCodec::grantOf($token));
        if ($reason !== null) {
            throw ApiException::unauthorized('Token has been revoked')->withReason($reason);
        }

        return $token;
    }
}
