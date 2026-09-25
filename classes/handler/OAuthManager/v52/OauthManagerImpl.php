<?php

namespace SRAG\PegasusHelper\handler\OAuthManager\v52;

use ilSession;
use SRAG\PegasusHelper\audit\AuditLog;
use SRAG\PegasusHelper\handler\BaseHandler;
use SRAG\PegasusHelper\handler\ChainRequestHandler;
use SRAG\PegasusHelper\oauth\Grant;
use SRAG\PegasusHelper\oauth\GrantGuard;
use SRAG\PegasusHelper\oauth\MisconfigurationException;
use SRAG\PegasusHelper\oauth\TokenCodec;
use SRAG\PegasusHelper\oauth\TokenService;

/**
 * Class OauthManager handles an authentication when a user
 * logs in from ILIAS Pegasus app.
 *
 * Mints the token pair itself via {@see TokenService}, rather than delegating
 * to the (now removed) ILIAS REST plugin's OAuth2 implementation.
 *
 * Since 7.3.0, if this request's session was itself derived from an app SSO
 * token (see {@see \SRAG\PegasusHelper\authentication\DefaultUserTokenAuthenticator}),
 * the newly issued pair inherits that login's {@see Grant} instead of minting
 * a brand-new one -- and is refused outright if that login has since been
 * revoked. Without this, a revoked user could still open an old SSO link in a
 * browser and mint a fresh, un-revoked token pair through this very route,
 * completely bypassing the revocation (SEC-02). A request with no such session
 * marker is a real ILIAS login, and gets a fresh grant as before.
 *
 * @author  Nicolas Märchy <nm@studer-raimann.ch>
 * @version 2.0.0
 *
 */
final class OauthManagerImpl extends BaseHandler implements ChainRequestHandler
{
    public const API_KEY = 'ilias_pegasus';

    /**
     * @var TokenService
     */
    private $tokens;

    /**
     * @var AuditLog
     */
    private $audit;

    /**
     * @var GrantGuard
     */
    private $guard;

    public function __construct(TokenService $tokens, AuditLog $audit, GrantGuard $guard)
    {
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->guard = $guard;
    }

    public function handle()
    {
        if (!$this->isHandler()) {
            parent::next();

            return;
        }

        global $ilUser;
        $userId = (int) $ilUser->getId();

        $grant = $this->resolveGrant($userId);
        if ($grant === null) {
            $this->audit->setActor($userId);
            $this->audit->log(AuditLog::EVENT_SSO_LOGIN_FAILED, AuditLog::LEVEL_WARNING, [
                'via' => 'app_oauth2',
                'reason' => 'revoked_session',
            ]);
            $this->respondUnavailable(403, 'This login session is no longer valid. Please sign in again.');

            return;
        }

        try {
            $data = $this->authenticate($grant, $userId, (string) $ilUser->getLogin());
        } catch (MisconfigurationException $e) {
            // A missing signing key/API secret is a server-side configuration
            // fault; never expose more than a generic message (SEC-01).
            $this->respondUnavailable(503, 'Pegasus login is currently unavailable. Please contact your administrator.');

            return;
        }

        $encodedData = implode('|||', $data);
        echo '<input type="hidden" name="data" id="data" value="' . htmlspecialchars($encodedData, ENT_QUOTES) . '">';
        die();
    }

    /**
     * Checks if the {@code target} GET parameter is set
     * and if its marked for Oauth of ILIAS Pegasus.
     *
     * @return boolean true if this handler needs to handle the request, otherwise false
     */
    private function isHandler()
    {
        global $ilUser;

        if (($_GET['target'] ?? null) !== 'ilias_app_oauth2') {
            return false;
        }

        return ($ilUser->getId() > 0 && $ilUser->getId() != ANONYMOUS_USER_ID);
    }

    /**
     * Determines which grant a newly issued token pair should inherit: the
     * session's own marker (if this request's session was derived from an app
     * SSO token, and that login is still valid), or a fresh one for a real
     * ILIAS login. Returns null if the session marker exists but its login has
     * been revoked -- the caller must refuse rather than silently minting a
     * fresh grant instead.
     */
    private function resolveGrant(int $userId): ?Grant
    {
        $marker = ilSession::get('pegasus_grant');
        if (!is_array($marker) || (int) ($marker['uid'] ?? 0) !== $userId) {
            return Grant::fresh($userId);
        }

        $sessionGrant = new Grant($userId, (int) ($marker['auth'] ?? 0), $marker['fam'] ?? null);

        return $this->guard->check($sessionGrant) === null ? $sessionGrant : null;
    }

    /**
     * Authenticates the user and returns data for Oauth2.
     * The data contains:
     * [<user_id>, "<username>", "<access_token>", "<refresh_token>"]
     *
     * e.g.
     * [1, "mmuster", "1454NRSM156trs4Nn54rNN45N5654R4R4N541RMN", "46554N5RN654RTN56N56RN4RT4DNR4R4S4"]
     *
     * @return array the resulting data
     *
     * @throws MisconfigurationException if the signing key/API secret is not configured
     */
    private function authenticate(Grant $grant, int $userId, string $login): array
    {
        $oauthData = $this->tokens->issuePair($grant);

        $this->audit->setActor($userId);
        $this->audit->log(AuditLog::EVENT_APP_LOGIN, AuditLog::LEVEL_NOTICE, [
            'refresh_fp' => AuditLog::fingerprint(TokenCodec::normalize($oauthData['refresh_token'])),
            'access_expires_in' => $oauthData['expires_in'],
        ]);

        return [
            $userId,
            $login,
            $oauthData['access_token'],
            $oauthData['refresh_token'],
        ];
    }

    private function respondUnavailable(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        die();
    }
}
