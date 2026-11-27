<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

/**
 * Base exception for HTTP-layer errors.
 *
 * Carries an HTTP status code (RFC 9110 §15) alongside the standard
 * exception message. Non-final: extended by InvalidHeaderException
 * for specialized header validation errors.
 *
 * Static factories provide concise construction for common codes.
 *
 * ARFA 1.3 P2: exceptions propagate up the middleware pipeline.
 *
 * Spec V4.0: non-final by design — extended by InvalidHeaderException.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6 HTTP/1.1 Response Status Codes
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return new self(405, $message);
    }

    public static function internalServerError(string $message = 'Internal Server Error'): self
    {
        return new self(500, $message);
    }
}

