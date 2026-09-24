<?php

/**
 * This script is meant to be deployed on a server OTHER than the ILIAS install
 * under test, so info.php can verify reachability from outside. Because its
 * whole job is "fetch a URL I'm told to fetch", it is inherently SSRF-shaped;
 * the guards below (a required shared secret, and a same-origin-request check)
 * keep it from being usable as an open proxy by random internet traffic.
 *
 * Setup: copy secret.php.dist to secret.php and set a random secret in it.
 * The script refuses every request (503) until that file exists.
 */

$internal_log = "request @ " . date("Y-m-d H:i:s") . PHP_EOL;
try {
    assertAuthorized();
    $internal_log .= "host   : " . $_GET["host"] . PHP_EOL;
    $log = performTest();
    setResponse($log);
    $internal_log .= "RESULT : " . print_r($log, true) . PHP_EOL;
} catch (Exception $e) {
    $internal_log .= "ERROR  : " . $e->getMessage() . PHP_EOL;
    setResponse(["cause" => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
}
// Kept outside the web root would be better still, but at minimum this file
// must never be served: see testing/.htaccess and the nginx note in HELP.md.
file_put_contents("check.log", $internal_log . PHP_EOL . PHP_EOL, FILE_APPEND);

/**
 * Requires a shared secret, checked with hash_equals(), configured locally in
 * secret.php (not committed -- see secret.php.dist). Fails closed: if the
 * secret file is missing, every request is rejected.
 *
 * @throws Exception with an HTTP-status-shaped code
 */
function assertAuthorized()
{
    $secretFile = __DIR__ . "/secret.php";
    if (!is_file($secretFile)) {
        throw new Exception(
            "This script is not configured. Copy secret.php.dist to secret.php and set a secret before use.",
            503
        );
    }

    $expected = (string) include $secretFile;
    $given = (string) ($_GET["secret"] ?? "");
    if ($expected === "" || $given === "" || !hash_equals($expected, $given)) {
        throw new Exception("Unauthorized", 403);
    }
}

/**
 * Validates that $host looks like a plain http(s) origin (scheme + hostname,
 * no path/query/credentials), and that it does not resolve to a private,
 * loopback, link-local or otherwise reserved address -- basic SSRF hardening,
 * on top of the secret above, in case the secret itself ever leaks.
 *
 * @param string $host
 * @throws Exception with an HTTP-status-shaped code
 */
function assertSafeTarget($host)
{
    $parts = parse_url($host);
    if ($parts === false
        || !isset($parts["scheme"], $parts["host"])
        || !in_array(strtolower($parts["scheme"]), ["http", "https"], true)
        || isset($parts["user"]) || isset($parts["path"]) || isset($parts["query"]) || isset($parts["fragment"])
    ) {
        throw new Exception("host must be a plain http(s) origin, e.g. https://ilias.example.org", 400);
    }

    $ip = filter_var($parts["host"], FILTER_VALIDATE_IP) ? $parts["host"] : gethostbyname($parts["host"]);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new Exception("host resolves to a private or reserved address", 400);
    }
}

/**
 * runs the test(s) and creates a log with the results
 *
 * @return mixed an array containing the info of the test(s)
 */
function performTest()
{
    $host = $_GET["host"];
    assertSafeTarget($host);

    $api = $host . "/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/PegasusHelper/api.php";
    // Any reachable, unauthenticated route works here: the point is only to prove
    // that this externally-hosted script can reach the PegasusHelper API at all.
    // A 401 (missing access token) is the expected, successful result.
    $url = $api . "/v2/ilias-app/desktop";

    $log = httpLoggedRequest($url, "GET", [], []);
    $log["host"] = "https://" . $_SERVER["HTTP_HOST"] . "//" . $_SERVER["REQUEST_URI"];

    return $log;
}

/**
 * sets the response headers and the body as a JSON
 *
 * @param string $bodyArr the body as an array
 * @param int $code the status code
 */
function setResponse($bodyArr, $code = 200)
{
    header_remove();

    http_response_code($code);
    header("Cache-Control: no-transform,public,max-age=300,s-maxage=900");
    header("Content-Type: application/json");
    $status = array(
        200 => "200 OK",
        400 => "400 Bad Request",
        403 => "403 Forbidden",
        500 => "500 Internal Server Error",
        503 => "503 Service Unavailable"
    );
    header("Status: " . ($status[$code] ?? "500 Internal Server Error"));

    echo json_encode($bodyArr);
}

function httpLoggedRequest($url, $method = "GET", $bodyArr = [], $headerArr = [])
{
    if ($method !== "GET" && $method !== "POST") {
        throw new Error("the argument \$method for httpLoggedRequest must be GET or POST");
    }
    $headerArr += [count($bodyArr) ? "Content-Type: application/json" : "User-Agent: srag (testing script)"];
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArr);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // never chase a redirect to a different (possibly internal) host
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    if (!count($bodyArr)) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyArr));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    $log["info"] = curl_getinfo($ch);
    $log["errno"] = curl_errno($ch);
    $log["errmsg"] = curl_error($ch);
    $log["response"] = json_decode($response);

    curl_close($ch);
    return $log;
}
