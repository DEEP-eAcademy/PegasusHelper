<?php

namespace SRAG\PegasusHelper\api\controller;

use SRAG\PegasusHelper\api\ApiException;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\Request;
use SRAG\PegasusHelper\authentication\AuthTokenRepository;

/**
 * Class AuthTokenController
 *
 * Serves `GET /v2/ilias-app/auth-token`: a short-lived, one-time token the app
 * uses to open ILIAS pages (via `goto.php`) and to download resources without
 * re-authenticating. Unlike the ILIAS REST plugin, this returns a real JSON
 * object -- REST's response was a JSON-encoded string containing JSON, so the
 * app's `.token` access was silently `undefined`.
 *
 * The SSO token is minted from the *grant* of the Bearer access token that
 * authenticated this request (see {@see Request::getGrant()}), not just its
 * user id, so it inherits the same login identity and is subject to the same
 * revocation/family checks (SEC-02) -- see {@see AuthTokenRepository::consume()}.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class AuthTokenController
{
    /**
     * @var AuthTokenRepository
     */
    private $tokens;

    public function __construct(AuthTokenRepository $tokens)
    {
        $this->tokens = $tokens;
    }

    public function __invoke(Request $request, array $params): JsonResponse
    {
        $grant = $request->getGrant();
        if ($grant === null) {
            // Can only happen if this route were ever wired up without Bearer
            // auth; fail rather than mint a token with no revocable identity.
            throw ApiException::serverError();
        }

        return new JsonResponse(['token' => $this->tokens->create($grant)]);
    }
}
