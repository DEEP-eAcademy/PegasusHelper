<?php

namespace SRAG\PegasusHelper\handler\OAuthManager\v52;

use SRAG\PegasusHelper\handler\BaseHandler;
use SRAG\PegasusHelper\handler\ChainRequestHandler;
use SRAG\PegasusHelper\oauth\TokenService;

/**
 * Class OauthManager handles an authentication when a user
 * logs in from ILIAS Pegasus app.
 *
 * Mints the token pair itself via {@see TokenService}, rather than delegating
 * to the (now removed) ILIAS REST plugin's OAuth2 implementation.
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

    public function __construct(TokenService $tokens)
    {
        $this->tokens = $tokens;
    }

    public function handle()
    {
        if ($this->isHandler()) {
            $data = $this->authenticate();
            $encodedData = implode('|||', $data);
            $out = '<input type="hidden" name="data" id="data" value="' . htmlspecialchars($encodedData, ENT_QUOTES) . '">';
            echo $out;
            die();
        }
        parent::next();
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
     * Authenticates the user and returns data for Oauth2.
     * The data contains:
     * [<user_id>, "<username>", "<access_token>", "<refresh_token>"]
     *
     * e.g.
     * [1, "mmuster", "1454NRSM156trs4Nn54rNN45N5654R4R4N541RMN", "46554N5RN654RTN56N56RN4RT4DNR4R4S4"]
     *
     * @return array the resulting data
     */
    private function authenticate()
    {
        /** @var $ilUser \ilObjUser */
        global $ilUser;

        $oauthData = $this->tokens->issuePair((int) $ilUser->getId());

        return [
            $ilUser->getId(),
            $ilUser->getLogin(),
            $oauthData['access_token'],
            $oauthData['refresh_token'],
        ];
    }
}
