<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

/**
 * The parts of the HTTP grammar the message objects check before they
 * accept a value: tokens (header names, methods), field values, protocol
 * versions, request targets and reason phrases. A value that passes cannot
 * end a header line early, so no header, status line or request line built
 * from it can carry text of its own.
 *
 * ARFA 1.3: P1 (pure functions over immutable strings). Spec V4.3.3:
 * stateless predicates, final.
 *
 * Preconditions: none; every function accepts any string.
 *
 * Thread-safety: stateless; safe to share between fibers.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     HttpGrammar::isToken('X-Request-Id');        // true
 *     HttpGrammar::isFieldValue("a\r\nX-Evil: 1");  // false
 *
 * References: RFC 9110 §5.1 (field names), §5.5 (field values), §5.6.2
 * (tokens), §9.1 (methods); RFC 9112 §2.3 (version), §3.2 (request
 * target), §4 (reason phrase); ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-5.6.2
 */
final class HttpGrammar
{
    /** tchar: the characters of a token, RFC 9110 §5.6.2. */
    private const string TOKEN = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/';

    /** field-vchar, SP and HTAB: everything but controls and DEL, RFC 9110 §5.5. */
    private const string FIELD_VALUE = '/\A[\x09\x20-\x7E\x80-\xFF]*\z/';

    /** No whitespace, no controls: a request target is one word. */
    private const string REQUEST_TARGET = '/\A[^\x00-\x20\x7F]+\z/';

    private const string PROTOCOL_VERSION = '/\A\d(?:\.\d)?\z/';

    public static function isToken(string $value): bool
    {
        return preg_match(self::TOKEN, $value) === 1;
    }

    public static function isFieldValue(string $value): bool
    {
        return preg_match(self::FIELD_VALUE, $value) === 1;
    }

    public static function isRequestTarget(string $value): bool
    {
        return preg_match(self::REQUEST_TARGET, $value) === 1;
    }

    public static function isProtocolVersion(string $value): bool
    {
        return preg_match(self::PROTOCOL_VERSION, $value) === 1;
    }

    /** A reason phrase follows the field-value rule (RFC 9112 §4). */
    public static function isReasonPhrase(string $value): bool
    {
        return self::isFieldValue($value);
    }
}
