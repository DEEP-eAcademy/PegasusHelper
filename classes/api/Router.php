<?php

namespace SRAG\PegasusHelper\api;

/**
 * Class Router
 *
 * A small regex router (method + pattern -> handler), replacing the Slim
 * framework the REST plugin used. Route patterns use `{name}` placeholders,
 * e.g. `/v2/ilias-app/objects/{refId}`.
 *
 * Each route also declares whether it requires Bearer authentication:
 *  - 'bearer' (the default): {@see ApiKernel} validates the Authorization header
 *    via TokenService and loads the ILIAS user before the handler runs.
 *  - 'none': the handler authenticates itself (used by `POST /v2/oauth2/token`,
 *    which authenticates via api_key/api_secret, and by the learning-module zip
 *    download, which authenticates via its own one-time `user`+`token` query
 *    parameters).
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class Router
{
    public const AUTH_BEARER = 'bearer';
    public const AUTH_NONE = 'none';

    /**
     * @var array<int, array{method:string, regex:string, names:string[], handler:callable, auth:string}>
     */
    private $routes = [];

    public function get(string $pattern, callable $handler, string $auth = self::AUTH_BEARER): void
    {
        $this->add('GET', $pattern, $handler, $auth);
    }

    public function post(string $pattern, callable $handler, string $auth = self::AUTH_BEARER): void
    {
        $this->add('POST', $pattern, $handler, $auth);
    }

    private function add(string $method, string $pattern, callable $handler, string $auth): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $m) use (&$names): string {
            $names[] = $m[1];

            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    /**
     * @param string $method
     * @param string $path
     * @return MatchedRoute
     *
     * @throws ApiException 404 if no route matches
     */
    public function match(string $method, string $path): MatchedRoute
    {
        $pathOnly = explode('?', $path, 2)[0];
        $found404ForOtherMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $pathOnly, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $found404ForOtherMethod = true;
                continue;
            }

            array_shift($m);
            $params = count($route['names']) > 0 ? array_combine($route['names'], $m) : [];

            return new MatchedRoute($route['handler'], $params, $route['auth']);
        }

        if ($found404ForOtherMethod) {
            throw new ApiException(405, ['cause' => 'Method not allowed']);
        }

        throw ApiException::notFound();
    }
}
