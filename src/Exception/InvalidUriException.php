<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\CarriesErrorCode;

/**
 * A URI or one of its components cannot be represented: a string
 * parse_url() rejects, a scheme outside RFC 3986's grammar, a host with
 * characters a host cannot hold (whitespace, "/", "?", "#", "@", or a ":"
 * outside an IPv6 literal), or a port outside 0–65535. PSR-7 asks for
 * \InvalidArgumentException, so the class extends it and carries its code
 * through CarriesErrorCode (ADR-003).
 *
 * Preconditions: messages name the component, never the value (E-047).
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $uri = (new Uri('https://example.com'))->withHost($input);
 *     } catch (InvalidUriException) {
 *         // refuse the input
 *     }
 *
 * References: RFC 3986 §3.1, §3.2.2, §3.2.3; PSR-7 UriInterface; ADR-003,
 * ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.2.2
 */
final class InvalidUriException extends \InvalidArgumentException implements Validation
{
    use CarriesErrorCode {
        CarriesErrorCode::__construct as private carry;
    }

    private function __construct(HttpCode $code, string $message)
    {
        $this->carry($code, $message);
    }

    public static function unparsable(): self
    {
        return new self(HttpCode::UnparsableUri, 'The URI cannot be parsed.');
    }

    public static function scheme(): self
    {
        return new self(HttpCode::InvalidScheme, 'A scheme starts with a letter followed by letters, digits, "+", "-" or ".".');
    }

    public static function host(): self
    {
        return new self(HttpCode::InvalidHost, 'A host holds a registered name, an IPv4 address or a bracketed IPv6 literal.');
    }

    public static function port(): self
    {
        return new self(HttpCode::InvalidPort, 'A port must be between 0 and 65535.');
    }
}
