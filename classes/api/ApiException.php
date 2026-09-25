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

    /**
     * @var string|null internal-only reason code for the audit log; never
     *                  sent to the client (see {@see getBody()})
     */
    private $reason;

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

    public static function payloadTooLarge(string $cause = 'Payload too large'): self
    {
        return new self(413, ['cause' => $cause]);
    }

    public static function serviceUnavailable(string $cause = 'Service unavailable', int $retryAfterSeconds = 300): self
    {
        return new self(503, ['cause' => $cause], ['Retry-After' => (string) $retryAfterSeconds]);
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

    /**
     * Attaches an internal-only reason code (e.g. 'expired', 'revoked'), for
     * the audit log. The HTTP response body is unaffected -- callers on the
     * wire cannot distinguish reasons this way, only the log can.
     *
     * @param string $reason
     * @return $this
     */
    public function withReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
