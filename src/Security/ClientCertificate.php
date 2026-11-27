<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

use KaririCode\Http\Message\Silently;

/**
 * What the server knows about a client's X.509 certificate after the TLS
 * layer verified it: subject and issuer DNs, serial, SHA-256 fingerprint,
 * validity period and the PEM. It comes from the web server's TLS variables
 * (fromServerParams()), from the headers of a trusted reverse proxy
 * (fromProxyHeaders()) or from an OpenSSLCertificate. When the PEM is known
 * and ext-openssl is loaded, the fingerprint is computed from it rather
 * than taken from a header. Dates are read only in the formats servers
 * write (OpenSSL "Nov 27 12:00:00 2027 GMT", ISO 8601), never as relative
 * expressions such as "now"; an unknown date stays null.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: the certificate was verified by the TLS layer; this
 * object does not verify signatures. commonName() and organization() read
 * RFC 4514 DNs with escapes ("O=a\,CN=admin" has no CN), and the OpenSSL
 * one-line form ("/C=BR/O=Org/CN=name").
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $certificate = ClientCertificate::fromServerParams($request->getServerParams());
 *     $certificate->commonName();                       // "api-client-7"
 *     $certificate->isWithinValidityPeriod($clock->now());
 *
 * References: RFC 5280 (X.509); RFC 4514 (DN strings); Apache mod_ssl
 * environment variables; nginx ngx_http_ssl_module variables; ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc4514
 */
final readonly class ClientCertificate
{
    private const array DATE_FORMATS = ['M j H:i:s Y T', \DATE_ATOM, 'Y-m-d\TH:i:s\Z'];

    public function __construct(
        public string $subjectDn,
        public string $issuerDn = '',
        public string $serialNumber = '',
        public string $fingerprint = '',
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public string $pem = '',
    ) {}

    /**
     * From the variables a TLS-terminating web server sets: SSL_CLIENT_S_DN,
     * SSL_CLIENT_I_DN, SSL_CLIENT_M_SERIAL, SSL_CLIENT_V_START,
     * SSL_CLIENT_V_END and SSL_CLIENT_CERT.
     *
     * @param array<array-key, mixed> $server
     */
    public static function fromServerParams(array $server): self
    {
        $value = static fn(string $key): string => self::text($server[$key] ?? null);
        $pem = $value('SSL_CLIENT_CERT');

        return new self(
            subjectDn: $value('SSL_CLIENT_S_DN'),
            issuerDn: $value('SSL_CLIENT_I_DN'),
            serialNumber: $value('SSL_CLIENT_M_SERIAL'),
            fingerprint: self::fingerprintOf($pem),
            validFrom: self::date($value('SSL_CLIENT_V_START')),
            validTo: self::date($value('SSL_CLIENT_V_END')),
            pem: $pem,
        );
    }

    /**
     * From X-SSL-Client-* headers. Use it only for requests from a trusted
     * proxy: anyone else can send these headers (ADR-007).
     *
     * @param array<string, string> $headers lower-case header name => value
     */
    public static function fromProxyHeaders(array $headers): self
    {
        $header = static fn(string ...$names): string => array_values(array_filter(array_map(static fn(string $name): string => $headers[$name] ?? '', $names), static fn(string $value): bool => $value !== ''))[0] ?? '';
        $pem = rawurldecode($header('x-ssl-client-cert'));
        $fingerprint = self::fingerprintOf($pem);

        return new self(
            subjectDn: $header('x-ssl-client-dn', 'x-client-dn'),
            issuerDn: $header('x-ssl-client-issuer', 'x-client-issuer-dn'),
            serialNumber: $header('x-ssl-client-serial'),
            fingerprint: $fingerprint !== '' ? $fingerprint : $header('x-ssl-client-sha256', 'x-ssl-client-fingerprint'),
            validFrom: self::date($header('x-ssl-client-notbefore')),
            validTo: self::date($header('x-ssl-client-notafter')),
            pem: $pem,
        );
    }

    public static function fromOpenSslCertificate(\OpenSSLCertificate $certificate): self
    {
        $parsed = openssl_x509_parse($certificate);

        if ($parsed === false) {
            return new self('');
        }

        $pem = '';
        $exported = openssl_x509_export($certificate, $pem);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        return new self(
            subjectDn: self::dnString($parsed['subject'] ?? null),
            issuerDn: self::dnString($parsed['issuer'] ?? null),
            serialNumber: self::text($parsed['serialNumberHex'] ?? null),
            fingerprint: $fingerprint === false ? '' : $fingerprint,
            validFrom: self::timestamp($parsed['validFrom_time_t'] ?? null),
            validTo: self::timestamp($parsed['validTo_time_t'] ?? null),
            pem: $exported ? self::text($pem) : '',
        );
    }

    /** The first CN of the subject, or '' when there is none. */
    public function commonName(): string
    {
        return self::attribute($this->subjectDn, 'CN');
    }

    /** The first O of the subject, or '' when there is none. */
    public function organization(): string
    {
        return self::attribute($this->subjectDn, 'O');
    }

    /** False when the end of the validity period is unknown. */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->validTo !== null && $now > $this->validTo;
    }

    /** False when the start of the validity period is unknown. */
    public function isNotYetValid(\DateTimeImmutable $now): bool
    {
        return $this->validFrom !== null && $now < $this->validFrom;
    }

    public function isWithinValidityPeriod(\DateTimeImmutable $now): bool
    {
        return !$this->isExpired($now) && !$this->isNotYetValid($now);
    }

    public function isEmpty(): bool
    {
        return $this->subjectDn === '';
    }

    private static function fingerprintOf(string $pem): string
    {
        if ($pem === '' || !function_exists('openssl_x509_fingerprint')) {
            return '';
        }

        $certificate = Silently::run(static fn(): \OpenSSLCertificate|false => openssl_x509_read($pem));
        $fingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');

        return $fingerprint === false ? '' : $fingerprint;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value), new \DateTimeZone('UTC'));

            if ($date !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        return null;
    }

    /** openssl_x509_parse() subject or issuer, as an RFC 4514 string. */
    private static function dnString(mixed $components): string
    {
        if (!is_array($components)) {
            return '';
        }

        $parts = [];

        foreach (array_keys($components) as $type) {
            foreach (self::values($components[$type]) as $value) {
                $parts[] = $type . '=' . addcslashes($value, ',+"\\<>;=');
            }
        }

        return implode(',', $parts);
    }

    /**
     * @return list<string>
     */
    private static function values(mixed $value): array
    {
        if (!is_array($value)) {
            return [self::text($value)];
        }

        $values = [];

        foreach (array_keys($value) as $key) {
            $values[] = self::text($value[$key]);
        }

        return $values;
    }

    private static function timestamp(mixed $time): ?\DateTimeImmutable
    {
        return is_int($time) ? new \DateTimeImmutable('@' . $time) : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function attribute(string $dn, string $type): string
    {
        foreach (DistinguishedName::parse($dn) as [$name, $value]) {
            if (strcasecmp($name, $type) === 0) {
                return $value;
            }
        }

        return '';
    }
}
