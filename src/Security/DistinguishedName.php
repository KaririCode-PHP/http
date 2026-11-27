<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * Reads the attribute-value pairs of a distinguished-name string, in the
 * two shapes web servers report: RFC 4514 ("CN=name,O=Org", with "\," and
 * "\2C" escapes, "+" between the values of one RDN and RFC 2253 quotes) and
 * the OpenSSL one-line form ("/C=BR/O=Org/CN=name"). An escaped separator
 * stays inside its value, so "O=a\,CN=admin" yields one O and no CN.
 *
 * ARFA 1.3: P1 (pure function). Spec V4.3.3: stateless parser, final.
 *
 * Preconditions: none; text that is not a DN yields the pairs it can read.
 * Spaces at either end of an unquoted value are dropped, escaped ones too.
 * The one-line form cannot escape "/", so a value holding "/" is ambiguous
 * there; servers that can send RFC 4514 should.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     DistinguishedName::parse('CN=api\, v2,O=Acme');  // [['CN', 'api, v2'], ['O', 'Acme']]
 *
 * References: RFC 4514 §2 and §3; RFC 2253 §4 (quoted values); OpenSSL
 * X509_NAME_oneline(); ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc4514#section-3
 */
final class DistinguishedName
{
    /**
     * @return list<array{string, string}>
     */
    public static function parse(string $dn): array
    {
        if (str_starts_with($dn, '/')) {
            return self::oneLine($dn);
        }

        $pairs = [];
        $length = strlen($dn);
        $position = 0;

        while ($position < $length) {
            [$type, $position] = self::until($dn, $position, '=');
            [$value, $position] = self::value($dn, $position + 1);
            $pairs[] = [trim($type), $value];
            ++$position;
        }

        return $pairs;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function oneLine(string $dn): array
    {
        $pairs = [];

        foreach (explode('/', $dn) as $component) {
            $pair = explode('=', $component, 2);

            if (count($pair) === 2) {
                $pairs[] = [trim($pair[0]), $pair[1]];
            }
        }

        return $pairs;
    }

    /**
     * Reads up to $stop, unescaped.
     *
     * @return array{string, int} text and the position of $stop (or the end)
     */
    private static function until(string $dn, int $position, string $stop): array
    {
        $end = strpos($dn, $stop, $position);
        $end = $end === false ? strlen($dn) : $end;

        return [substr($dn, $position, $end - $position), $end];
    }

    /**
     * Reads one attribute value: quoted, or up to an unescaped ",", ";" or "+".
     *
     * @return array{string, int} value and the position of its separator (or the end)
     */
    private static function value(string $dn, int $position): array
    {
        $length = strlen($dn);
        $quoted = ($dn[$position] ?? '') === '"';
        $position += $quoted ? 1 : 0;
        $value = '';

        while ($position < $length) {
            $char = $dn[$position];

            if ($char === '\\') {
                [$unescaped, $position] = self::escape($dn, $position + 1);
                $value .= $unescaped;

                continue;
            }

            if ($quoted ? $char === '"' : in_array($char, [',', ';', '+'], true)) {
                break;
            }

            $value .= $char;
            ++$position;
        }

        if ($quoted) {
            [, $position] = self::until($dn, $position, ',');
        }

        return [$quoted ? $value : trim($value), $position];
    }

    /**
     * "\," or "\2C" after the backslash at $position - 1.
     *
     * @return array{string, int} the character and the position after the escape
     */
    private static function escape(string $dn, int $position): array
    {
        $pair = substr($dn, $position, 2);

        if (strlen($pair) === 2 && ctype_xdigit($pair)) {
            return [pack('H*', $pair), $position + 2];
        }

        return [$dn[$position] ?? '', $position + 1];
    }
}
