<?php

/**
 * collection of information for tests, about the current installation
 */

function getInfo()
{
    $info = [];

    $ilDB_handle = initIlDB();
    $info["TestScript"] = getTestScriptInfo($ilDB_handle);
    $info["Connectivity"] = getConnectivityInfo();
    $info["ILIAS"] = getILIASInfo();
    $info["PegasusHelper"] = getPluginInfo("PegasusHelper", "sragpegasushelper", $ilDB_handle);
    // Only used to warn if the (no longer required) REST plugin is still active;
    // gracefully degrades to "not available" once its directory/DB entry is gone.
    $info["RestPluginLegacy"] = getPluginInfo("REST", "rest", $ilDB_handle);
    closeIlDB($ilDB_handle);

    return $info;
}

function getTestScriptInfo($ilDB_handle)
{
    $testScript_info = [];

    $location = "Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing";
    $testScript_info["correct_working_directory"] = isset($GLOBALS["ilias"]) || (substr(getcwd(), -strlen($location)) === $location);
    $testScript_info["ilDB_connection"] = isset($ilDB_handle);

    preg_match("/^\d+(\.\d+)*/", phpversion(), $match);
    $testScript_info["php_version"] = $match[0];

    $testScript_info["available"] = true;
    return $testScript_info;
}

function getConnectivityInfo()
{
    $connectivity_info = [];
    $err_msg = "WARNING unable to get some Information about the connectivity";

    try {
        $host = parse_ini_file(getRootIliasConfig() . "/ilias.ini.php", true)["server"]["http_path"];
        $url_api = $host . "/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php/v2/ilias-app/desktop";

        // A GET with no Authorization header must be rejected (401, "missing").
        $connectivity_info["api_no_token"] = httpLoggedRequest($url_api);
        // A GET with a bogus (but present) Authorization header must be rejected
        // for a *different* reason (401, "invalid") -- proving the header actually
        // reached PHP, which some server/proxy configurations strip.
        $connectivity_info["api_bad_token"] = httpLoggedRequest($url_api, "GET", [], ["Authorization: Bearer not-a-real-token"]);
    } catch (Exception $e) {
        addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
    }

    $external_host = ""; // TODO setup backend (summarize urls in constants-file?)
    if (strlen(trim($external_host)) > 0) {
        try {
            $connectivity_info["external_url"] = httpLoggedRequest($external_host);
        } catch (Exception $e) {
            addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        }

        $external_script = $external_host . "/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/testing/external/run.php";
        $client = "default"; // TODO get client id
        try {
            $urlTestScript = $external_script . "?host=" . urlencode($host);
            $urlTestScript .= "&client_id=" . urlencode($client);
            $connectivity_info["external_testing"] = httpLoggedRequest($urlTestScript);
        } catch (Exception $e) {
            addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        }
    } else {
        addToLog("\n" . $err_msg . "\nExternal host for PegasusHelper testing is not configured.\n");
    }

    return $connectivity_info;
}

function getILIASInfo()
{
    $ilias_info = [];
    $err_msg = "WARNING unable to get some Information about ILIAS";

    try {
        include_once getRootIliasConfig() . "/ilias_version.php";
        $ilias_info["version"] = ILIAS_VERSION_NUMERIC;

        $ilias_info["available"] = true;
    } catch (Exception $e) {
        addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        $ilias_info["available"] = false;
    }

    try {
        $ilias_info["ilias_ini"] = parse_ini_file(getRootIliasConfig() . "/ilias.ini.php", true);
        $ilias_info["ilias_ini"]["available"] = true;
    } catch (Exception $e) {
        addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        $ilias_info["ilias_ini"]["available"] = false;
    }

    return $ilias_info;
}

function getPluginInfo($plugin_dir, $plugin_id, $ilDB_handle)
{
    $plugin_info = [];
    $err_msg = "WARNING unable to get some Information about plugin " . $plugin_dir;

    try {
        include getRootPlugins() . "/" . $plugin_dir . "/plugin.php";
        $plugin_info["version"] = $version;
        $plugin_info["ilias_min_version"] = $ilias_min_version;
        $plugin_info["ilias_max_version"] = $ilias_max_version;

        $plugin_info["available"] = true;
    } catch (Exception $e) {
        addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        $plugin_info["available"] = false;
    }

    try {
        $query = "SELECT last_update_version, active, db_version FROM ilias.il_plugin WHERE plugin_id = '" . $plugin_id . "'";
        $result = queryAndFetchIlDB($ilDB_handle, $query);
        if (!$result) {
            $query = "SELECT last_update_version, active, db_version FROM il_plugin WHERE plugin_id = '" . $plugin_id . "'";
            $result = queryAndFetchIlDB($ilDB_handle, $query);
        }

        $plugin_info += ["ilDB" => $result];
        $plugin_info["ilDB"]["available"] = (bool) $result;
    } catch (Exception $e) {
        addToLog("\n" . $err_msg . "\n" . $e->getMessage() . "\n");
        $plugin_info["ilDB"]["available"] = false;
    }

    return $plugin_info;
}
