<?php

namespace SRAG\PegasusHelper\api;

/**
 * Class MatchedRoute
 *
 * The result of a successful {@see Router::match()} call.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class MatchedRoute
{
    /**
     * @var callable
     */
    private $handler;

    /**
     * @var array<string, string>
     */
    private $params;

    /**
     * @var string one of Router::AUTH_*
     */
    private $auth;

    /**
     * @var string the route pattern as registered, e.g. `/v1/files/{refId}`
     *             -- kept for the audit log, so entries group by route rather
     *             than by raw (id-bearing) path
     */
    private $pattern;

    public function __construct(callable $handler, array $params, string $auth, string $pattern)
    {
        $this->handler = $handler;
        $this->params = $params;
        $this->auth = $auth;
        $this->pattern = $pattern;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }
}
