<?php

/**
 * tests to be performed are implemented here and must be added to $testsList in the getTestSuite-function below
 *
 * template for a test-function:
 *
 * function testNAME($info, $targetInfo, $suite) {
 *     // compute test based on $info, $targetInfo, $suite
 *     return [$pass, $msg, $mandatory, $complete];
 * }
 *
 */

/**
 * creates a collection of tests without HTTP requests
 *
 * @param $context int for TestingContext
 * @return TestSuite
 */
function getInternalTestSuite($context)
{
    $suite = new TestSuite("Internal Testing", $context);

    $general = new TestCategory("General");
    $general->addTests([
        new Test("location where script is run", "testWorkingDirectory", true, [TestingContext::C_CLI]),
        new Test("location of PegasusHelper-plugin", "testPegasusHelperDirectory"),
        new Test("connection to ILIAS-database", "testIlBDConnection", false, [TestingContext::C_CLI]),
        new Test("compatible PHP-version", "testPhpVersion", false)
    ]);
    $suite->addCategories($general);

    $ilias = new TestCategory("ILIAS");
    $ilias->addTests([
        new Test("compatible ILIAS-version", "testILIASVersion"),
        new Test("https redirects", "testILIASRedirectStatement", false)
    ]);
    $suite->addCategories($ilias);

    $pegasusHelper = new TestCategory("PegasusHelper-plugin");
    $pegasusHelper->addTests([
        new Test("version", "testPegasusHelperVersion", false),
        new Test("compatible ILIAS-version", "testPegasusHelperMinMaxVersion"),
        new Test("entry in ILIAS-database", "testPegasusHelperInIlDB"),
        new Test("plugin-updates in ILIAS", "testPegasusHelperLastUpdateVersion", false),
        new Test("ILIAS-database version", "testPegasusHelperDbVersion"),
        new Test("active", "testPegasusHelperPluginActive")
    ]);
    $suite->addCategories($pegasusHelper);

    $api = new TestCategory("PegasusHelper API");
    $api->addTests([
        new Test("api.php rejects a request without a token", "testApiRejectsMissingToken"),
        new Test("Authorization header reaches PHP", "testApiReceivesAuthorizationHeader"),
        new Test("legacy REST plugin no longer active", "testRestPluginNotActive", false)
    ]);
    $suite->addCategories($api);

    return $suite;
}

/**
 * creates a collection of tests with HTTP requests
 *
 * @param $context int for TestingContext
 * @return TestSuite
 */
function getExternalTestsSuite($context)
{
    $suite = new TestSuite("External Testing", $context);

    $pegasusHelper = new TestCategory("Accessing Resources");
    $pegasusHelper->addTests([
        new Test("external URL", "testExternalUrl", false),
        new Test("PegasusHelper API reachable", "testApiConnection", false)
    ]);
    $suite->addCategories($pegasusHelper);

    $pegasusHelper = new TestCategory("External Script");
    $pegasusHelper->addTests([
        new Test("successful run", "testExternalTestScriptComplete", false)
    ]);
    $suite->addCategories($pegasusHelper);

    return $suite;
}

// 0 General

function testWorkingDirectory($info, $targetInfo, $suite)
{
    $pass = $info["TestScript"]["correct_working_directory"];
    $msg = $pass ? "" : "the script must be run from [YOUR_ILIAS]/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing";
    return completeTestResult($pass, $msg);
}

function testPegasusHelperDirectory($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["correct_working_directory"]) {
        return failTestFromMissingInfo("wrong working directory");
    }
    $pass = file_exists(getRootPlugins() . "/PegasusHelper");
    $msg = $pass ? "" : "PegasusHelper must be located at [YOUR_ILIAS]/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper";
    return completeTestResult($pass, $msg);
}

function testIlBDConnection($info, $targetInfo, $suite)
{
    $pass = $info["TestScript"]["ilDB_connection"];
    $msg = $pass ? "succeeded" : "connection to ILIAS-database failed, some tests cannot be performed";
    return completeTestResult($pass, $msg);
}

function testPhpVersion($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["php_version"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testVersionIs($info["TestScript"]["php_version"], $targetInfo["TestScript"]["php_version"]);
    return completeTestResult($pass, $msg);
}

// 1 ILIAS

function testILIASVersion($info, $targetInfo, $suite)
{
    if (!$info["ILIAS"]["available"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testMinMaxVersion($info["ILIAS"]["version"], $targetInfo["ILIAS"]["min_version"], $targetInfo["ILIAS"]["max_version"]);
    return completeTestResult($pass, $msg);
}

function testILIASRedirectStatement($info, $targetInfo, $suite)
{
    if (!$info["ILIAS"]["ilias_ini"]["available"]) {
        return failTestFromMissingInfo();
    }
    $pass = isset($info["ILIAS"]["ilias_ini"]["server"]["http_path"]);
    $msg = $pass ? "" : "if requests to ILIAS are redirected to https, then the file ilias.ini.php must be configured accordingly";
    return completeTestResult($pass, $msg);
}

// 3 PegasusHelper

function testPegasusHelperVersion($info, $targetInfo, $suite)
{
    if (!$info["PegasusHelper"]["available"]) {
        return failTestFromMissingInfo();
    }
    if (!$targetInfo["PegasusHelper"]["available"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testVersionIs($info["PegasusHelper"]["version"], $targetInfo["PegasusHelper"]["version"], "the latest version is [TARGET] and the one used here is [VERSION]");
    return completeTestResult($pass, $msg);
}

function testPegasusHelperMinMaxVersion($info, $targetInfo, $suite)
{
    if (!$info["ILIAS"]["available"]) {
        return failTestFromMissingInfo();
    }
    if (!$info["PegasusHelper"]["available"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testMinMaxVersion($info["ILIAS"]["version"], $info["PegasusHelper"]["ilias_min_version"], $info["PegasusHelper"]["ilias_max_version"]);
    return completeTestResult($pass, $msg);
}

function testPegasusHelperInIlDB($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["ilDB_connection"]) {
        return failTestFromMissingInfo("connection to ILIAS-database required");
    }
    list($pass, $msg) = testInIlDB($info["PegasusHelper"]);
    if (!$pass) {
        failTestFromMissingInfo($msg);
    } // TODO this may fail with installed plugins (mysql user?)
    return completeTestResult($pass, $msg);
}

function testPegasusHelperLastUpdateVersion($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["ilDB_connection"]) {
        return failTestFromMissingInfo("connection to ILIAS-database required");
    }
    if (!$info["PegasusHelper"]["ilDB"]["available"]) {
        return failTestFromMissingInfo("plugin is not (correctly) installed");
    }
    if (!$info["PegasusHelper"]["available"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testVersionIs($info["PegasusHelper"]["ilDB"]["last_update_version"], $info["PegasusHelper"]["version"], "some plugin-updates in ILIAS are not installed");
    return completeTestResult($pass, $msg);
}

function testPegasusHelperDbVersion($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["ilDB_connection"]) {
        return failTestFromMissingInfo("connection to ILIAS-database required");
    }
    if (!$info["PegasusHelper"]["ilDB"]["available"]) {
        return failTestFromMissingInfo("plugin is not (correctly) installed");
    }
    if (!$targetInfo["PegasusHelper"]["ilDB"]["available"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testVersionIs($info["PegasusHelper"]["ilDB"]["db_version"], $targetInfo["PegasusHelper"]["ilDB"]["db_version"]);
    return completeTestResult($pass, $msg);
}

function testPegasusHelperPluginActive($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["ilDB_connection"]) {
        return failTestFromMissingInfo("connection to ILIAS-database required");
    }
    if (!$info["PegasusHelper"]["ilDB"]["available"]) {
        return failTestFromMissingInfo("plugin is not (correctly) installed");
    }
    list($pass, $msg) = testPluginActive($info["PegasusHelper"]["ilDB"]);
    return completeTestResult($pass, $msg);
}

// 3b PegasusHelper API

function testApiRejectsMissingToken($info, $targetInfo, $suite)
{
    if (!isset($info["Connectivity"]["api_no_token"]["response"]["status"])) {
        return failTestFromMissingInfo();
    }
    $status = $info["Connectivity"]["api_no_token"]["response"]["status"];
    $pass = $status === 401;
    $msg = $pass ? "" : "expected HTTP 401 for a request without an Authorization header, got $status";
    return completeTestResult($pass, $msg);
}

function testApiReceivesAuthorizationHeader($info, $targetInfo, $suite)
{
    if (!isset($info["Connectivity"]["api_bad_token"]["response"]["status"])) {
        return failTestFromMissingInfo();
    }
    $status = $info["Connectivity"]["api_bad_token"]["response"]["status"];
    $body = $info["Connectivity"]["api_bad_token"]["response"]["body"];
    $message = is_array($body) ? ($body["message"] ?? "") : "";

    // A bogus (but present) Authorization header must fail for a *different*
    // reason than a missing one ("invalid" vs "missing"), which proves the
    // header actually reached PHP -- some server/proxy configurations strip it.
    $pass = $status === 401 && stripos($message, "invalid") !== false;
    $msg = $pass ? "" : "expected an 'invalid token' error for a bogus Authorization header, got status $status / '$message'";
    return completeTestResult($pass, $msg);
}

function testRestPluginNotActive($info, $targetInfo, $suite)
{
    if (!$info["TestScript"]["ilDB_connection"]) {
        return failTestFromMissingInfo("connection to ILIAS-database required");
    }
    if (!$info["RestPluginLegacy"]["ilDB"]["available"]) {
        // No database entry at all: the REST plugin has already been fully removed.
        return completeTestResult(true, "");
    }
    $pass = !((bool) $info["RestPluginLegacy"]["ilDB"]["active"]);
    $msg = $pass ? "" : "the ILIAS REST plugin is still active; PegasusHelper no longer needs it and it should be uninstalled";
    return completeTestResult($pass, $msg);
}

// 4 External

function testExternalUrl($info, $targetInfo, $suite)
{
    if (!$info["Connectivity"]["external_url"]["response"]["status"]) {
        return failTestFromMissingInfo();
    }
    list($pass, $msg) = testHttpResponseStatus($info["Connectivity"]["external_url"]["response"]["status"]);
    return completeTestResult($pass, $msg);
}

function testApiConnection($info, $targetInfo, $suite)
{
    if (!isset($info["Connectivity"]["api_no_token"]["response"]["status"])) {
        return failTestFromMissingInfo();
    }
    // An unauthenticated request is expected to be rejected with 401, which
    // proves the request reached api.php and was routed correctly.
    $status = $info["Connectivity"]["api_no_token"]["response"]["status"];
    $pass = $status === 401;
    $msg = $pass ? "" : "expected HTTP 401 (missing token), received status $status";
    return completeTestResult($pass, $msg);
}

function testExternalTestScriptComplete($info, $targetInfo, $suite)
{
    if (!$info["Connectivity"]["external_testing"]) {
        return failTestFromMissingInfo();
    }
    // run.php's own (curl-based) httpLoggedRequest() puts the real target status
    // under body.info.http_code (curl_getinfo), not body.response.
    $status = $info["Connectivity"]["external_testing"]["response"]["body"]["info"]["http_code"] ?? null;
    $pass = $status === 401;
    $msg = $pass ? "" : "expected the external script to receive HTTP 401 from api.php, got " . var_export($status, true);
    return completeTestResult($pass, $msg);
}

// * Multiple

/**
 * @param $v string
 * @param $v_min string
 * @param $v_max string
 * @return array
 */
function testMinMaxVersion($v, $v_min, $v_max)
{
    $v_arr = strVersionToArray($v);
    $v_min_arr = strVersionToArray($v_min);
    $v_max_arr = strVersionToArray($v_max);

    $pass = (arrayVersionSmallerThan($v_min_arr, $v_arr, true)) &&
        (arrayVersionSmallerThan($v_arr, $v_max_arr, true));
    $msg = $pass ? "version " . $v : "the version " . $v . " is not contained in " . $v_min . " and " . $v_max;
    return [$pass, $msg];
}

/**
 * Checks for versions wether $version >= $target. the custom message $msg_fail uses tags [TARGET] and [VERSION]
 *
 * @param $version string
 * @param $target string
 * @param $msg_fail string
 * @return array
 */
function testVersionIs($version, $target, $msg_fail = "version must be [TARGET] but is [VERSION]")
{
    $pass = arrayVersionSmallerThan(strVersionToArray($target), strVersionToArray($version), true);
    $msg = $pass ? "version " . $version : str_replace("[VERSION]", $version, str_replace("[TARGET]", $target, $msg_fail));
    return [$pass, $msg];
}

function testInIlDB($plugin_info)
{
    $pass = $plugin_info["ilDB"]["available"];
    $msg = $pass ? "" : "the plugin must be installed";
    return [$pass, $msg];
}

function testPluginActive($plugin_info)
{
    $pass = (bool) $plugin_info["active"];
    $msg = $pass ? "" : "the plugin must be activated";
    return [$pass, $msg];
}

function testHttpResponseStatus($status)
{
    $pass = $status == 200;
    $msg = $pass ? "" : "received status $status";
    return [$pass, $msg];
}
