<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\CarriesErrorCode;

/**
 * An argument to a message object breaks the HTTP grammar or PSR-7: a
 * header name that is not a token, a header value with CR, LF or another
 * control character (which would let the value start a header of its own),
 * a method that is not a token, a malformed protocol version, request
 * target, status code or reason phrase, an uploaded-file tree with
 * something else in it, a parsed body that is neither array, object nor
 * null, an empty move target, a stream argument that is not a stream, a
 * file mode PHP would misread, or a normalizer called with something other
 * than a PSR-7 server request.
 * PSR-7 asks for \InvalidArgumentException here, so the class extends it
 * and carries its code through CarriesErrorCode instead of extending a
 * kariricode/exception base (ADR-003).
 *
 * Preconditions: messages name the rule, never the rejected value, which
 * may come from a client (errata E-047); a header name goes through
 * quote().
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $response = $response->withHeader('Location', $target);
 *     } catch (InvalidMessageException) {
 *         $response = $response->withStatus(400);
 *     }
 *
 * References: RFC 9110 §5.1, §5.5, §5.6.2, §9.1, §15; RFC 9112 §3.2, §4;
 * PSR-7; KaririCode Spec V4.3.3 §8.4; ADR-003, ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-5.5
 */
final class InvalidMessageException extends \InvalidArgumentException implements Validation
{
    use CarriesErrorCode {
        CarriesErrorCode::__construct as private carry;
    }

    private function __construct(HttpCode $code, string $message)
    {
        $this->carry($code, $message);
    }

    public static function headerName(): self
    {
        return new self(HttpCode::InvalidHeaderName, 'A header name must be an RFC 9110 token.');
    }

    public static function headerValue(string $name): self
    {
        return new self(HttpCode::InvalidHeaderValue, sprintf('Header %s needs a string value without CR, LF or other control characters.', self::quote($name)));
    }

    public static function method(): self
    {
        return new self(HttpCode::InvalidMethod, 'A request method must be an RFC 9110 token.');
    }

    public static function protocolVersion(): self
    {
        return new self(HttpCode::InvalidProtocolVersion, 'A protocol version is a digit, optionally followed by a dot and a digit.');
    }

    public static function requestTarget(): self
    {
        return new self(HttpCode::InvalidRequestTarget, 'A request target must not be empty or contain whitespace or control characters.');
    }

    public static function statusCode(): self
    {
        return new self(HttpCode::InvalidStatusCode, 'A status code must be between 100 and 599.');
    }

    public static function reasonPhrase(): self
    {
        return new self(HttpCode::InvalidReasonPhrase, 'A reason phrase must not contain CR, LF or other control characters.');
    }

    public static function uploadedFiles(): self
    {
        return new self(HttpCode::InvalidUploadedFiles, 'Uploaded files must be a tree of UploadedFileInterface instances.');
    }

    public static function parsedBody(): self
    {
        return new self(HttpCode::InvalidParsedBody, 'A parsed body must be an array, an object or null.');
    }

    public static function targetPath(): self
    {
        return new self(HttpCode::InvalidTargetPath, 'The target path of an uploaded file must not be empty or contain a NUL byte.');
    }

    public static function notAStreamResource(): self
    {
        return new self(HttpCode::NotAStreamResource, 'A stream needs an open stream resource.');
    }

    public static function notAServerRequest(): self
    {
        return new self(HttpCode::NotAServerRequest, 'HttpNormalizer::normalize() needs a PSR-7 server request; call canNormalize() first.');
    }

    public static function streamMode(string $mode): self
    {
        return new self(HttpCode::InvalidStreamMode, sprintf('The stream mode %s is not r, w, a, x or c followed by "+", "b", "t", "e" or "n".', self::quote($mode)));
    }
}
