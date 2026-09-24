<?php

namespace SRAG\PegasusHelper\api;

/**
 * Class JsonResponse
 *
 * A tiny value object a controller returns to describe a JSON response. Sending
 * it (headers + body) is done by {@see ApiKernel}, so controllers stay easy to
 * unit test.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class JsonResponse
{
    /**
     * @var int
     */
    private $statusCode;

    /**
     * @var mixed
     */
    private $body;

    /**
     * @param mixed $body any JSON-serializable value
     */
    public function __construct($body, int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }
}
