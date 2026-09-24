<?php

namespace SRAG\PegasusHelper\api;

use ilContext;
use ilIniFile;
use ilObject;
use ilObjUser;

/**
 * Class ApiInitialisation
 *
 * Boots a bare ILIAS instance for api.php, and loads an ILIAS user without a web
 * session. This is a close port of the (production-proven) ILIAS REST plugin's
 * `RESTController\libs\RESTilias::initILIAS()` / `loadIlUser()` and its
 * namespaced `ilInitialisation` subclass, adapted to ILIAS 10.
 *
 * It extends the real \ilInitialisation only to gain access to its protected
 * static `initAccessHandling()`/`initGlobal()` -- this class is never used as
 * an actual ILIAS bootstrap participant outside of api.php.
 *
 * Context choice: CONTEXT_REST's `hasUser()` is false, so ILIAS's own
 * `initILIAS()` never creates a user, `ilAccess` or the `ilRbacSystem`
 * singleton -- both bind to whatever user exists in the DIC at the moment
 * they are first constructed, so the user MUST be assigned before anything
 * touches access control. Using any context with `hasUser() === true` would
 * make ILIAS create its own (anonymous) user and access objects first, and
 * those could then never be swapped out.
 *
 * `hasHTML()` is also false for CONTEXT_REST, so ILIAS never registers
 * `ui.factory` and friends on its own. `ilObjFile::sendFile()` unconditionally
 * needs `ui.factory` (its `update()` call touches copyright metadata), so it is
 * registered explicitly below -- this only wires up lazy Pimple factories, none
 * of which get eagerly instantiated, so it is cheap and has no side effects on
 * requests that never need it.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiInitialisation extends \ilInitialisation
{
    /**
     * @var bool
     */
    private static $booted = false;

    /**
     * Boots ILIAS for the current request. Safe to call more than once; only
     * the first call has an effect.
     *
     * @param string|null $clientIdHint an ILIAS client id read (without verification)
     *                                  from the request, e.g. from a token's `ilias_client`
     *                                  field. ILIAS 10's `determineClient()` only reads
     *                                  `$_GET['client_id']`, so this must be set before init.
     */
    public static function boot(?string $clientIdHint): void
    {
        if (self::$booted) {
            return;
        }

        $publicDir = self::locatePublicDir();
        chdir($publicDir);

        $root = dirname($publicDir);
        $autoloader = $root . '/vendor/composer/vendor/autoload.php';
        if (is_file($autoloader)) {
            require_once $autoloader;
        }

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', $root);
        }

        unset($_GET['client_id']);
        if ($clientIdHint !== null && preg_match('/^[A-Za-z0-9_-]+$/', $clientIdHint)) {
            $_GET['client_id'] = $clientIdHint;
        }

        \ilContext::init(\ilContext::CONTEXT_REST);

        // ILIAS builds ILIAS_HTTP_PATH from $_SERVER['HTTP_HOST'] + a heuristic
        // that strips everything after the first ".php" in REQUEST_URI, which
        // would otherwise yield ".../PegasusHelper/api.php" for every URL. Fake
        // these three values the same way the REST plugin does, then restore them.
        $original = [
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '',
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
            'PHP_SELF' => $_SERVER['PHP_SELF'] ?? '',
        ];
        $_SERVER['REQUEST_URI'] = '';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['HTTP_HOST'] = self::iniHttpHost($root);

        ob_start();
        \ilInitialisation::initILIAS();
        ob_end_clean();

        global $DIC;
        if (isset($DIC) && !isset($DIC['ui.factory']) && method_exists('\ilInitialisation', 'initUIFramework')) {
            \ilInitialisation::initUIFramework($DIC);
        }

        header_remove('Set-Cookie');

        $_SERVER['HTTP_HOST'] = $original['HTTP_HOST'];
        $_SERVER['REQUEST_URI'] = $original['REQUEST_URI'];
        $_SERVER['PHP_SELF'] = $original['PHP_SELF'];

        self::$booted = true;
    }

    /**
     * Loads an ILIAS user with no web session, and initializes access handling
     * (rbacreview/rbacsystem/ilAccess) for that user. Must be called after
     * {@see boot()} and before any `checkAccess()` call.
     *
     * @param int $userId
     * @return ilObjUser
     *
     * @throws ApiException 401 if the user id is invalid, anonymous, unknown,
     *                      inactive, or outside its account time limit
     */
    public static function loadUser(int $userId): ilObjUser
    {
        if ($userId <= 0 || (defined('ANONYMOUS_USER_ID') && $userId === ANONYMOUS_USER_ID)) {
            throw ApiException::unauthorized('Invalid token');
        }
        if (!ilObject::_exists($userId, false, 'usr') || !ilObjUser::_lookupActive($userId)) {
            throw ApiException::unauthorized('Invalid token');
        }

        $user = new ilObjUser($userId);
        if (!$user->checkTimeLimit()) {
            throw ApiException::unauthorized('Invalid token');
        }

        global $DIC, $ilias;
        $DIC['ilUser'] = $user;
        self::initAccessHandling();
        if (isset($ilias)) {
            $ilias->account = $user;
        }
        parent::initLanguage(true);

        return $user;
    }

    /**
     * Walks up from this file's location to find ILIAS's public webroot
     * (the directory containing `ilias.php`), independent of exactly how many
     * directory levels this plugin happens to be nested under it.
     *
     * @return string absolute path to `public/`, no trailing slash
     */
    private static function locatePublicDir(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 20; $i++) {
            if (is_file($dir . '/ilias.php')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Could not locate the ILIAS public/ webroot above ' . __DIR__);
    }

    /**
     * @param string $root absolute path to the ILIAS installation root (one level above public/)
     * @return string the [server] http_path from ilias.ini.php, with the scheme stripped
     */
    private static function iniHttpHost(string $root): string
    {
        $ini = new ilIniFile($root . '/ilias.ini.php');
        $ini->read();
        $httpPath = (string) $ini->readVariable('server', 'http_path');

        return preg_replace('#^https?://#', '', $httpPath);
    }
}
