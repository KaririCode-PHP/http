<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

/**
 * Thrown when an HTTP header name or value fails validation.
 *
 * Carries HTTP 400 status for downstream error handlers.
 * RFC 9110 §5.1 defines the allowed field-name grammar.
 *
 * ARFA 1.3 P2: exceptions propagate up the middleware pipeline.
 *
 * Spec V4.0: final class, static factories enforce consistent messages.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
 */
final class InvalidHeaderException extends HttpException
{
    public static function invalidName(string $headerName): self
    {
        return new self($headerName, 'contains invalid characters per RFC 9110 §5.1');
    }

    public static function invalidValue(string $headerName): self
    {
        return new self($headerName, 'value contains invalid characters per RFC 9110 §5.5');
    }

    public static function emptyName(): self
    {
        return new self('', 'header name must not be empty');
    }

    public function __construct(string $headerName, string $reason = '')
    {
        $message = "Invalid header \"{$headerName}\"";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }

        parent::__construct(400, $message);
    }
}

