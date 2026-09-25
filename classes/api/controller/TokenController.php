<?php

namespace SRAG\PegasusHelper\api\controller;

use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\api\ApiInitialisation;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\Request;
use SRAG\PegasusHelper\oauth\TokenService;

/**
 * Class TokenController
 *
 * Serves `POST /v2/oauth2/token`. Only `grant_type=refresh_token` is supported --
 * the app only ever refreshes an existing pair; the initial pair is minted at
 * login by {@see \SRAG\PegasusHelper\handler\OAuthManager\v52\OauthManagerImpl}.
 *
 * This is an {@see \SRAG\PegasusHelper\api\Router::AUTH_NONE} route (it
 * authenticates itself, via the refresh token and the client secret), so
 * unlike a Bearer route {@see \SRAG\PegasusHelper\api\ApiKernel} never calls
 * {@see ApiInitialisation::loadUser()} for it. The eligibility callback passed
 * to {@see TokenService::refresh()} is what closes that gap: it makes a
 * deactivated, deleted or time-limited-out user's refresh token stop working
 * (SEC-03), exactly as it already would for any Bearer route.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class TokenController
{
    /**
     * @var TokenService
     */
    private $tokens;

    public function __construct(TokenService $tokens)
    {
        $this->tokens = $tokens;
    }

    public function __invoke(Request $request, array $params): JsonResponse
    {
        $grantType = $request->param('grant_type');
        if ($grantType !== 'refresh_token') {
            throw ApiException::badRequest('Unsupported grant_type');
        }

        $apiKey = $request->param('api_key') ?? $request->param('client_id');
        $apiSecret = $request->param('api_secret') ?? $request->param('client_secret');
        $refreshToken = $request->param('refresh_token');

        $checkEligibility = static function (int $userId): void {
            ApiInitialisation::loadUser($userId);
        };

        return new JsonResponse($this->tokens->refresh($apiKey, $apiSecret, $refreshToken, $checkEligibility));
    }
}
