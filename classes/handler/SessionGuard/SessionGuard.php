<?php

namespace SRAG\PegasusHelper\handler\SessionGuard;

use SRAG\PegasusHelper\handler\ChainRequestHandler;

/**
 * Interface SessionGuard
 *
 * Marks the chain participant that periodically re-checks a browser session
 * derived from an app SSO token against {@see \SRAG\PegasusHelper\oauth\GrantGuard},
 * and logs it out if the login it was derived from has since been revoked
 * (SEC-02). See {@see \SRAG\PegasusHelper\handler\SessionGuard\v1\SessionGuardImpl}
 * for why this must run *before* {@see \SRAG\PegasusHelper\handler\ExcludedHandler\ExcludedHandler}
 * in the chain, unlike every other handler.
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
interface SessionGuard extends ChainRequestHandler
{
}
