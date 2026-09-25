<?php

namespace SRAG\PegasusHelper\handler\SessionGuard\v1;

use ilAuthSession;
use ilSession;
use ilUtil;
use SRAG\PegasusHelper\audit\AuditLog;
use SRAG\PegasusHelper\handler\BaseHandler;
use SRAG\PegasusHelper\handler\SessionGuard\SessionGuard;
use SRAG\PegasusHelper\oauth\Grant;
use SRAG\PegasusHelper\oauth\GrantGuard;
use Throwable;

/**
 * Class SessionGuardImpl
 *
 * A browser session created by {@see \SRAG\PegasusHelper\authentication\DefaultUserTokenAuthenticator}
 * (i.e. one opened via an app SSO link) carries a `pegasus_grant` session
 * marker. Without this handler, that session would keep working indefinitely
 * even after an admin revokes the user, revokes everyone, or rotates the
 * signing salt: none of those actions touch an already-authenticated ILIAS
 * session, only tokens (SEC-02).
 *
 * Unlike every other handler in the chain, this one must run *before*
 * {@see \SRAG\PegasusHelper\handler\ExcludedHandler\ExcludedHandler}: that
 * handler deliberately short-circuits the whole chain unless
 * `$_GET['target']` starts with `ilias_app`, which is exactly the case for
 * ordinary repository browsing in a session opened from an SSO link -- and
 * ordinary browsing is precisely when this check needs to run. See
 * {@see \ilPegasusHelperUIHookGUI}, which wires the chain accordingly.
 *
 * The check itself is throttled to once every {@see RECHECK_INTERVAL_SECONDS}
 * per session (a fresh timestamp is stamped into the marker on every pass),
 * so a revoked session is caught quickly without adding two DB lookups to
 * every single page request.
 *
 * Every failure mode falls through to {@see next()}: this is a defence-in-depth
 * layer on top of the token-level checks that already fail closed (an access
 * token, refresh token or SSO ticket derived from a revoked login is rejected
 * regardless of whether this handler ever runs), so a bug here must never be
 * able to break or lock a normal ILIAS session out of the whole UI.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class SessionGuardImpl extends BaseHandler implements SessionGuard
{
    private const SESSION_KEY = 'pegasus_grant';
    private const RECHECK_INTERVAL_SECONDS = 30;

    /**
     * @var GrantGuard
     */
    private $guard;

    /**
     * @var AuditLog
     */
    private $audit;

    public function __construct(GrantGuard $guard, AuditLog $audit)
    {
        $this->guard = $guard;
        $this->audit = $audit;
    }

    public function handle()
    {
        try {
            if (!$this->checkAndMaybeLogout()) {
                $this->next();
            }
        } catch (Throwable $e) {
            // See class docblock: never let this defence-in-depth check itself
            // break or lock out a normal session.
            $this->next();
        }
    }

    /**
     * @return bool true if the session was just logged out and a redirect was
     *              sent (the caller must NOT call next() in that case)
     */
    private function checkAndMaybeLogout(): bool
    {
        global $ilUser, $DIC;

        if (!isset($ilUser) || !is_object($ilUser)) {
            return false;
        }
        $userId = (int) $ilUser->getId();
        if ($userId <= 0 || (defined('ANONYMOUS_USER_ID') && $userId === ANONYMOUS_USER_ID)) {
            return false;
        }

        $marker = ilSession::get(self::SESSION_KEY);
        if (!is_array($marker) || (int) ($marker['uid'] ?? 0) !== $userId) {
            return false;
        }

        $lastCheck = (int) ($marker['chk'] ?? 0);
        if ((time() - $lastCheck) < self::RECHECK_INTERVAL_SECONDS) {
            return false;
        }

        $grant = new Grant($userId, (int) ($marker['auth'] ?? 0), $marker['fam'] ?? null);
        $reason = $this->guard->check($grant);

        if ($reason === null) {
            $marker['chk'] = time();
            ilSession::set(self::SESSION_KEY, $marker);

            return false;
        }

        $this->audit->setActor($userId);
        $this->audit->log(AuditLog::EVENT_SESSION_TERMINATED, AuditLog::LEVEL_NOTICE, ['reason' => $reason]);

        if (isset($DIC) && is_object($DIC) && $DIC->offsetExists('ilAuthSession')) {
            /** @var ilAuthSession $authSession */
            $authSession = $DIC['ilAuthSession'];
            $authSession->logout();
        }

        header('Location: ' . rtrim(ilUtil::_getHttpPath(), '/') . '/login.php');

        exit;
    }
}
