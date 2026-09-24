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

    public function __construct(callable $handler, array $params, string $auth)
    {
        $this->handler = $handler;
        $this->params = $params;
        $this->auth = $auth;
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
}
