<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * Immutable representation of a parsed client TLS certificate.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly class
 * with static factories for different extraction sources.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): constructed from proxy
 * headers (HTTP behind nginx/Caddy), stream context (WebSocket
 * direct TLS), or gRPC metadata — same value object everywhere.
 *
 * Spec V4.0: final readonly class.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5280 X.509 PKI Certificate Profile
 */
final readonly class ClientCertificate
{
    /**
     * @param string               $subjectDn   Subject Distinguished Name (e.g. "CN=service-a,O=Acme")
     * @param string               $issuerDn    Issuer Distinguished Name
     * @param string               $serialNumber Certificate serial number (hex)
     * @param string               $fingerprint SHA-256 fingerprint of the certificate
     * @param \DateTimeImmutable   $validFrom   Not-Before date
     * @param \DateTimeImmutable   $validTo     Not-After date
     * @param string               $pem         Full PEM-encoded certificate (may be empty)
     */
    public function __construct(
        public string $subjectDn,
        public string $issuerDn = '',
        public string $serialNumber = '',
        public string $fingerprint = '',
        public \DateTimeImmutable $validFrom = new \DateTimeImmutable('1970-01-01'),
        public \DateTimeImmutable $validTo = new \DateTimeImmutable('1970-01-01'),
        public string $pem = '',
    ) {
    }

    // ── Static Factories ─────────────────────────────────────────────────

    /**
     * Constructs from reverse-proxy headers.
     *
     * Common header names (configurable in nginx/Caddy/HAProxy):
     *   X-SSL-Client-DN      → Subject DN
     *   X-SSL-Client-Issuer  → Issuer DN
     *   X-SSL-Client-Serial  → Serial number
     *   X-SSL-Client-SHA256  → SHA-256 fingerprint
     *   X-SSL-Client-Cert    → URL-encoded PEM certificate
     *   X-SSL-Client-NotBefore → Valid-from date
     *   X-SSL-Client-NotAfter  → Valid-to date
     *
     * @param array<string, string> $headers Lowercase header names → values
     */
    public static function fromProxyHeaders(array $headers): self
    {
        $subjectDn = $headers['x-ssl-client-dn'] ?? $headers['x-client-dn'] ?? '';
        $issuerDn = $headers['x-ssl-client-issuer'] ?? $headers['x-client-issuer-dn'] ?? '';
        $serial = $headers['x-ssl-client-serial'] ?? '';
        $fingerprint = $headers['x-ssl-client-sha256'] ?? $headers['x-ssl-client-fingerprint'] ?? '';
        $pem = $headers['x-ssl-client-cert'] ?? '';

        if ($pem !== '') {
            $pem = urldecode($pem);
        }

        $validFrom = self::parseDate($headers['x-ssl-client-notbefore'] ?? '');
        $validTo = self::parseDate($headers['x-ssl-client-notafter'] ?? '');

        return new self($subjectDn, $issuerDn, $serial, $fingerprint, $validFrom, $validTo, $pem);
    }

    /**
     * Constructs from an OpenSSL X.509 resource/object.
     *
     * Used when PHP terminates TLS directly (WebSocket server,
     * Swoole, ReactPHP) and has access to the peer certificate
     * via stream_context capture_peer_cert.
     *
     * @param \OpenSSLCertificate $cert X.509 certificate resource
     */
    public static function fromOpenSslCertificate(\OpenSSLCertificate $cert): self
    {
        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            return new self('');
        }

        $subjectDn = self::buildDnString($parsed['subject'] ?? []);
        $issuerDn = self::buildDnString($parsed['issuer'] ?? []);
        $serial = $parsed['serialNumberHex'] ?? '';

        $derOut = '';
        openssl_x509_export($cert, $derOut);
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256') ?: '';

        $validFrom = isset($parsed['validFrom_time_t'])
            ? (new \DateTimeImmutable())->setTimestamp($parsed['validFrom_time_t'])
            : new \DateTimeImmutable('1970-01-01');

        $validTo = isset($parsed['validTo_time_t'])
            ? (new \DateTimeImmutable())->setTimestamp($parsed['validTo_time_t'])
            : new \DateTimeImmutable('1970-01-01');

        return new self($subjectDn, $issuerDn, $serial, $fingerprint, $validFrom, $validTo, $derOut);
    }

    // ── Query Methods ────────────────────────────────────────────────────

    public function commonName(): string
    {
        if (preg_match('/CN=([^,\/]+)/', $this->subjectDn, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    public function organization(): string
    {
        if (preg_match('/O=([^,\/]+)/', $this->subjectDn, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $now > $this->validTo;
    }

    public function isNotYetValid(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $now < $this->validFrom;
    }

    public function isWithinValidityPeriod(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return !$this->isExpired($now) && !$this->isNotYetValid($now);
    }

    public function isEmpty(): bool
    {
        return $this->subjectDn === '';
    }

    // ── Internal Helpers ─────────────────────────────────────────────────

    /**
     * @param array<string, string|list<string>> $components
     */
    private static function buildDnString(array $components): string
    {
        $parts = [];
        foreach ($components as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $parts[] = "{$key}={$v}";
            }
        }

        return implode(',', $parts);
    }

    private static function parseDate(string $value): \DateTimeImmutable
    {
        if ($value === '') {
            return new \DateTimeImmutable('1970-01-01');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable('1970-01-01');
        }
    }
}
