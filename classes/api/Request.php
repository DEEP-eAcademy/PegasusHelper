<?php

namespace SRAG\PegasusHelper\api;

use SRAG\PegasusHelper\oauth\Grant;

/**
 * Class Request
 *
 * A minimal HTTP request abstraction for the api.php entry point, built directly
 * from PHP superglobals before ILIAS is bootstrapped (the ILIAS HTTP service is
 * not available yet at that point, and CONTEXT_REST does not register it anyway).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class Request
{
    /**
     * Hard cap on the request body, checked before it is even fully read (see
     * {@see parseBody()}). Every route this plugin serves needs at most a
     * handful of short form fields; this is generous headroom over that, not
     * a limit tuned to any expected payload.
     */
    private const MAX_BODY_BYTES = 65536;

    /**
     * @var string
     */
    private $method;

    /**
     * @var string
     */
    private $path;

    /**
     * @var array
     */
    private $query;

    /**
     * @var array
     */
    private $parsedBody;

    /**
     * @var string|null
     */
    private $bearerToken;

    /**
     * @var Grant|null set by {@see ApiKernel} once a Bearer token has been
     *                 validated, so a handler that mints a derived credential
     *                 (e.g. an SSO auth-token) can inherit the same login
     *                 identity rather than a bare user id
     */
    private $grant;

    private function __construct(string $method, string $path, array $query, array $parsedBody, ?string $bearerToken, ?Grant $grant = null)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->parsedBody = $parsedBody;
        $this->bearerToken = $bearerToken;
        $this->grant = $grant;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = self::resolvePath();
        $query = $_GET;
        $parsedBody = self::parseBody($method);
        $bearerToken = self::resolveBearerToken();

        return new self($method, $path, $query, $parsedBody, $bearerToken);
    }

    /**
     * @return self a clone carrying the validated Bearer token's grant
     */
    public function withGrant(Grant $grant): self
    {
        return new self($this->method, $this->path, $this->query, $this->parsedBody, $this->bearerToken, $grant);
    }

    public function getGrant(): ?Grant
    {
        return $this->grant;
    }

    private static function resolvePath(): string
    {
        // PATH_INFO is set when the web server passes the part of the URL after
        // the script name through to PHP (the normal case for api.php/v2/... ).
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if ($pathInfo !== '') {
            return '/' . ltrim($pathInfo, '/');
        }

        // Fallback: derive the route from REQUEST_URI, which also makes a plain
        // rewrite from the old .../REST/api.php/... URL work, since the rewritten
        // REQUEST_URI still contains "api.php/<route>".
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = explode('?', $uri, 2)[0];
        $marker = 'api.php';
        $pos = strpos($uri, $marker);
        if ($pos === false) {
            return '/';
        }
        $path = substr($uri, $pos + strlen($marker));

        return $path === '' ? '/' : '/' . ltrim($path, '/');
    }

    /**
     * @throws ApiException 413 if the declared or actual body size exceeds
     *         {@see MAX_BODY_BYTES} -- checked *before* the body is read in
     *         full, so an oversized payload can't be used to load the server
     *         merely by being decoded (SEC-10). This runs before ILIAS itself
     *         is even booted, so the response is a bare JSON error, exactly
     *         like every other early rejection in {@see ApiKernel}.
     */
    private static function parseBody(string $method): array
    {
        if ($method !== 'POST') {
            return [];
        }

        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null && ctype_digit((string) $contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw ApiException::payloadTooLarge();
        }

        // Read via an explicit stream handle rather than file_get_contents()'s
        // offset/maxlen parameters: php://input is not seekable, and relying
        // on offset=0 happening to be a no-op is fragile across PHP/SAPI
        // versions. stream_get_contents()'s $maxLength caps how much is ever
        // read into memory, independent of (and not trusting) Content-Length.
        $handle = @fopen('php://input', 'rb');
        $raw = $handle !== false ? stream_get_contents($handle, self::MAX_BODY_BYTES + 1) : false;
        if ($handle !== false) {
            fclose($handle);
        }

        if ($raw === false || $raw === '') {
            return $_POST;
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw ApiException::payloadTooLarge();
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        // application/x-www-form-urlencoded body that PHP did not auto-parse
        // (this can happen depending on how the request reached PHP).
        parse_str($raw, $parsed);

        return $parsed;
    }

    private static function resolveBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if ($header === null) {
            return null;
        }

        if (stripos($header, 'Bearer ') === 0) {
            return substr($header, 7);
        }

        return $header;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBearerToken(): ?string
    {
        return $this->bearerToken;
    }

    /**
     * Reads a parameter, preferring the parsed body, falling back to the query string.
     * Matches the REST plugin's `RESTRequest::readParameters()` precedence closely
     * enough for our single POST route (body before query).
     *
     * @param string      $name
     * @param string|null $default
     * @return string|null
     */
    public function param(string $name, ?string $default = null): ?string
    {
        if (array_key_exists($name, $this->parsedBody)) {
            return (string) $this->parsedBody[$name];
        }
        if (array_key_exists($name, $this->query)) {
            return (string) $this->query[$name];
        }

        return $default;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return array_key_exists($name, $this->query) ? (string) $this->query[$name] : $default;
    }

    public function queryBool(string $name): bool
    {
        $value = $this->query[$name] ?? null;

        return $value !== null && $value !== '0' && $value !== 'false';
    }
}
