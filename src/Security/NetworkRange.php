<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * An IPv4 or IPv6 address or CIDR range, as a trusted-proxy entry: parse()
 * accepts "10.0.0.1", "10.0.0.0/8", "2001:db8::/32" and "::1", and
 * contains() tells whether an address belongs to the range. Comparison is
 * on the packed bytes (inet_pton), so "010.0.0.1" or a mapped IPv6 form
 * cannot pass for a listed address by spelling.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: none; parse() returns null for anything it cannot read.
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     NetworkRange::parse('10.0.0.0/8')?->contains('10.1.2.3');  // true
 *     NetworkRange::parse('10.0.0.0/8')?->contains('11.0.0.1');  // false
 *
 * References: RFC 4632 (CIDR); RFC 4291 §2.3 (IPv6 prefixes); ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc4632
 */
final readonly class NetworkRange
{
    private function __construct(
        private string $network,
        private int $prefix,
    ) {}

    public static function parse(string $range): ?self
    {
        $parts = explode('/', $range, 2);
        $packed = filter_var($parts[0], FILTER_VALIDATE_IP) === false ? false : inet_pton($parts[0]);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if (count($parts) === 2 && !ctype_digit($parts[1])) {
            return null;
        }

        $prefix = count($parts) === 2 ? (int) $parts[1] : $bits;

        return $prefix <= $bits ? new self($packed, $prefix) : null;
    }

    public function contains(string $address): bool
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false || strlen($packed) !== strlen($this->network)) {
            return false;
        }

        $whole = intdiv($this->prefix, 8);
        $rest = $this->prefix % 8;

        if (substr($packed, 0, $whole) !== substr($this->network, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = 256 - (1 << (8 - $rest)); // the top $rest bits of a byte

        return (ord($packed[$whole]) & $mask) === (ord($this->network[$whole]) & $mask);
    }
}
