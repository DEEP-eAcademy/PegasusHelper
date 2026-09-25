<?php

namespace SRAG\PegasusHelper\oauth;

use RuntimeException;

/**
 * Class MisconfigurationException
 *
 * Thrown when the plugin cannot safely mint or validate a token because a
 * required secret (the signing salt, or the `ilias_pegasus` API key/secret) is
 * missing or empty. Deliberately distinct from {@see \SRAG\PegasusHelper\api\ApiException}:
 * a missing secret is a server-side configuration fault, not a client error, so
 * it must never be reported with a 4xx status or with a message that hints at
 * *why* the request failed (see SEC-01: "fail closed without a signing key").
 *
 * Caught by {@see \SRAG\PegasusHelper\api\ApiKernel} (which turns it into a
 * generic 500) and by {@see \SRAG\PegasusHelper\handler\OAuthManager\v52\OauthManagerImpl}
 * (which turns it into a plain-text "login unavailable" page instead of a fatal
 * error visible to the browser).
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class MisconfigurationException extends RuntimeException
{
}
