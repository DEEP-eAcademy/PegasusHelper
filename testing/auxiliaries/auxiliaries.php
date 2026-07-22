<?php

/**
 * helper-functions to perform the tests
 */

function getRootIlias()
{
    return isset($GLOBALS["ilias"]) ? "." : "../../../../../../../..";
}

/**
 * ILIAS 10 serves requests from the "public" directory, which is one level
 * below the actual installation root. ilias.ini.php and ilias_version.php
 * live in that installation root, one level above {@see getRootIlias()}.
 */
function getRootIliasConfig()
{
    return getRootIlias() . "/..";
}

function getRootPlugins()
{
    return getRootIlias() . "/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook";
}

function strVersionToArray($version)
{
    return array_map("intval", explode(".", $version));
}

function arrayVersionToStr($version)
{
    return implode(".", $version);
}

function arrayVersionSmallerThan($v1, $v2, $includeEqual = false)
{
    $cnt = min(count($v1), count($v2));
    for ($i = 0; $i < $cnt; $i++) {
        if ($v1[$i] > $v2[$i]) {
            return false;
        }
        if ($v1[$i] < $v2[$i]) {
            return true;
        }
    }
    return $includeEqual;
}

function higherStrVersion($v1, $v2)
{
    return arrayVersionSmallerThan(
        strVersionToArray($v2),
        strVersionToArray($v1)
    ) ? $v1 : $v2;
}

function lowerStrVersion($v1, $v2)
{
    return arrayVersionSmallerThan(
        strVersionToArray($v1),
        strVersionToArray($v2)
    ) ? $v1 : $v2;
}
