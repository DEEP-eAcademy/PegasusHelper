<?php
/**
 * File api.php
 *
 * Entry point for the ILIAS Pegasus app's JSON API.
 *
 * Replaces the routes the app used to call under
 * ".../UserInterfaceHook/REST/api.php" (the third-party ILIAS REST plugin,
 * which this plugin no longer depends on as of version 7.0.0). Kept as a plain
 * script rather than a class, so ILIAS's ctrl-structure build tooling -- which
 * scans every plugin class file via reflection, with no request context and no
 * `$DIC` -- never has a reason to load it.
 *
 * @author Nicolas Schäfli <ns@studer-raimann.ch>
 */

require_once __DIR__ . '/vendor/autoload.php';

SRAG\PegasusHelper\api\ApiKernel::run();
