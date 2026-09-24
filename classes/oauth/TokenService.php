<?php

namespace SRAG\PegasusHelper\oauth;

use SRAG\PegasusHelper\api\ApiException;

/**
 * Class TokenService
 *
 * Issues and validates the OAuth2 access/refresh token pair used by the Pegasus
 * app, replacing the ILIAS REST plugin's `core/oauth2_v2` for the single
 * `ilias_pegasus` API client this plugin serves.
 *
 * Access tokens are stateless: any token whose signature and expiry check out is
 * accepted, exactly as the REST plugin behaved (its `ui_uihk_rest_access` table
 * was written to but never actually consulted to reject a token early). Refresh
 * tokens are tracked in {@see RefreshTokenRepository}, because a refresh token
 * must keep working after being used until it expires on its own -- the app can
 * fire multiple concurrent refreshes with the same refresh token.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class TokenService
{
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

    public function __construct(TokenCodec $codec, ApiSettings $settings, RefreshTokenRepository $refreshTokens)
    {
        $this->codec = $codec;
        $this->settings = $settings;
        $this->refreshTokens = $refreshTokens;
    }

    /**
     * Issues a new access/refresh token pair for the given user and records the
     * refresh token.
     *
     * @param int $userId
     * @return array{access_token:string,refresh_token:string,expires_in:int,token_type:string,scope:null}
     */
    public function issuePair(int $userId): array
    {
        $apiKey = $this->settings->getApiKey();
        $iliasClient = defined('CLIENT_ID') ? CLIENT_ID : '';

        $accessArray = $this->codec->generate($userId, $iliasClient, $apiKey, TokenCodec::CLASS_ACCESS, $this->settings->getAccessTokenTtlMinutes());
        $refreshArray = $this->codec->generate($userId, $iliasClient, $apiKey, TokenCodec::CLASS_REFRESH, $this->settings->getRefreshTokenTtlMinutes());

        $accessToken = $this->codec->serialize($accessArray);
        $refreshToken = $this->codec->serialize($refreshArray);

        $this->refreshTokens->insert($refreshToken, $userId);

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
        if ($rawToken === null || $rawToken === '') {
            throw ApiException::unauthorized('Missing access token');
        }

        $token = $this->decodeAndCheck($rawToken, TokenCodec::CLASS_ACCESS);

        return (int) $token['user_id'];
    }

    /**
     * Handles the `grant_type=refresh_token` flow of `POST /v2/oauth2/token`.
     *
     * @param string|null $apiKey
     * @param string|null $apiSecret
     * @param string|null $refreshToken
     * @return array{access_token:string,refresh_token:string,expires_in:int,token_type:string,scope:null}
     *
     * @throws ApiException 400 on a malformed request, 401 on bad credentials or an invalid/expired refresh token
     */
    public function refresh(?string $apiKey, ?string $apiSecret, ?string $refreshToken): array
    {
        if ($refreshToken === null || $refreshToken === '') {
            throw ApiException::badRequest('Missing refresh_token');
        }
        if ($apiKey === null || $apiKey === '') {
            throw ApiException::badRequest('Missing api_key');
        }

        if (!hash_equals($this->settings->getApiKey(), $apiKey)) {
            throw ApiException::unauthorized('Unknown client');
        }
        if (!hash_equals($this->settings->getApiSecret(), (string) $apiSecret)) {
            throw ApiException::unauthorized('Invalid client secret');
        }

        $token = $this->decodeAndCheck($refreshToken, TokenCodec::CLASS_REFRESH);
        $normalized = $this->codec->normalize($refreshToken);

        if (!$this->refreshTokens->exists($normalized)) {
            throw ApiException::unauthorized('Refresh token has been revoked');
        }

        $this->refreshTokens->touch($normalized);

        // Issue a fresh pair, but keep the old refresh token valid until it expires
        // on its own: the app can fire several concurrent refreshes with the same
        // refresh token, and invalidating it immediately would break that.
        return $this->issuePair((int) $token['user_id']);
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

        if ($token === null || !$this->codec->isValid($token)) {
            throw ApiException::unauthorized('Invalid token');
        }
        if ($token['class'] !== $expectedClass) {
            throw ApiException::unauthorized('Invalid token');
        }
        if ($this->codec->isExpired($token)) {
            throw ApiException::unauthorized('Token has expired');
        }
        if (!hash_equals($this->settings->getApiKey(), (string) $token['api_key'])) {
            throw ApiException::unauthorized('Invalid token');
        }
        $iliasClient = defined('CLIENT_ID') ? CLIENT_ID : '';
        if (!hash_equals($iliasClient, (string) $token['ilias_client'])) {
            throw ApiException::unauthorized('Invalid token');
        }

        return $token;
    }
}
