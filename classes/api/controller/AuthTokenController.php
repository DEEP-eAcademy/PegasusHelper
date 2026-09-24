<?php

namespace SRAG\PegasusHelper\api\controller;

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
        global $DIC;
        $userId = (int) $DIC->user()->getId();

        return new JsonResponse(['token' => $this->tokens->create($userId)]);
    }
}
