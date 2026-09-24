<?php

namespace SRAG\PegasusHelper\api;

use Exception;
use Throwable;

/**
 * Class ApiException
 *
 * An exception carrying an HTTP status code and a JSON-serializable body, thrown
 * by controllers and the token service to signal an error response. Caught and
 * rendered by {@see ApiKernel}.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class ApiException extends Exception
{
    /**
     * @var array
     */
    private $body;

    /**
     * @var array
     */
    private $headers;

    public function __construct(int $statusCode, array $body, array $headers = [], ?Throwable $previous = null)
    {
        parent::__construct($body['cause'] ?? $body['message'] ?? 'API error', $statusCode, $previous);
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function notFound(string $cause = 'Not found'): self
    {
        return new self(404, ['cause' => $cause]);
    }

    public static function forbidden(string $cause = 'Forbidden'): self
    {
        return new self(403, ['cause' => $cause]);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, ['message' => $message]);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, ['message' => $message], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function serverError(string $cause = 'Internal Server Error'): self
    {
        return new self(500, ['cause' => $cause]);
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getStatusCode(): int
    {
        return $this->code;
    }
}
