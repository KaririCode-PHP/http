<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

use KaririCode\Http\Exception\InvalidConfigurationException;

/**
 * TLS settings for a server and its client-certificate policy: the
 * server's certificate and key, the CA that signs client certificates,
 * the verify mode and depth, cipher choices, an optional allow-list of
 * subject DNs, pinned SHA-256 fingerprints, and the reverse proxies whose
 * X-SSL-Client-* headers may be believed. Without trusted proxies only the
 * TLS variables of the web server count (SSL_CLIENT_*), which a client
 * cannot set (ADR-007).
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new configuration). Spec
 * V4.3.3: final readonly Value Object, validated on construction.
 *
 * Preconditions: $verifyDepth ≥ 1 (InvalidConfigurationException 1765);
 * each trusted proxy is an IPv4 or IPv6 address or CIDR range (1763).
 * $allowedDns use the format the web server reports (RFC 4514 for nginx,
 * the OpenSSL one-line format for Apache by default).
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $tls = TlsConfiguration::mutual('/etc/tls/server.pem', '/etc/tls/server.key', '/etc/tls/clients-ca.pem')
 *         ->withTrustedProxies(['10.0.0.0/8'])
 *         ->withTrustedFingerprints(['3f:2a:…']);
 *
 * References: PHP manual, SSL context options; RFC 8446; RFC 4514 (DN
 * strings); ADR-007.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 */
final readonly class TlsConfiguration
{
    /**
     * @param list<string> $allowedDns          subject DNs allowed; empty allows any verified one
     * @param list<string> $trustedFingerprints pinned SHA-256 fingerprints, with or without colons
     * @param list<string> $trustedProxies      addresses or CIDR ranges whose X-SSL-Client-* headers count
     */
    public function __construct(
        public ?string $certFile = null,
        public ?string $keyFile = null,
        public ?string $caFile = null,
        public ?string $caPath = null,
        public PeerVerifyMode $peerVerifyMode = PeerVerifyMode::None,
        public int $verifyDepth = 10,
        public ?string $cipherList = null,
        public ?string $cipherSuites = null,
        public array $allowedDns = [],
        public array $trustedFingerprints = [],
        public array $trustedProxies = [],
    ) {
        if ($verifyDepth < 1) {
            throw InvalidConfigurationException::verifyDepth();
        }

        foreach ($trustedProxies as $proxy) {
            if (NetworkRange::parse($proxy) === null) {
                throw InvalidConfigurationException::trustedProxy($proxy);
            }
        }
    }

    public static function serverOnly(string $certFile, string $keyFile): self
    {
        return new self(certFile: $certFile, keyFile: $keyFile);
    }

    public static function mutual(string $certFile, string $keyFile, string $caFile): self
    {
        return new self(certFile: $certFile, keyFile: $keyFile, caFile: $caFile, peerVerifyMode: PeerVerifyMode::Require);
    }

    public static function optionalClient(string $certFile, string $keyFile, string $caFile): self
    {
        return new self(certFile: $certFile, keyFile: $keyFile, caFile: $caFile, peerVerifyMode: PeerVerifyMode::Optional);
    }

    public function withPeerVerifyMode(PeerVerifyMode $mode): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $mode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints, $this->trustedProxies);
    }

    public function withVerifyDepth(int $depth): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $depth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints, $this->trustedProxies);
    }

    public function withCipherList(string $cipherList): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints, $this->trustedProxies);
    }

    public function withCipherSuites(string $cipherSuites): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $cipherSuites, $this->allowedDns, $this->trustedFingerprints, $this->trustedProxies);
    }

    /**
     * @param list<string> $dns
     */
    public function withAllowedDns(array $dns): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $dns, $this->trustedFingerprints, $this->trustedProxies);
    }

    /**
     * @param list<string> $fingerprints
     */
    public function withTrustedFingerprints(array $fingerprints): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $fingerprints, $this->trustedProxies);
    }

    /**
     * @param list<string> $proxies
     */
    public function withTrustedProxies(array $proxies): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints, $proxies);
    }

    public function isMutualTls(): bool
    {
        return $this->peerVerifyMode !== PeerVerifyMode::None && $this->caFile !== null;
    }

    public function hasServerCertificate(): bool
    {
        return $this->certFile !== null && $this->keyFile !== null;
    }

    public function hasDnRestriction(): bool
    {
        return $this->allowedDns !== [];
    }

    public function hasFingerprintPinning(): bool
    {
        return $this->trustedFingerprints !== [];
    }

    public function isDnAllowed(string $subjectDn): bool
    {
        return $this->allowedDns === [] || in_array($subjectDn, $this->allowedDns, true);
    }

    /** Compares hex digits only, case-insensitively; colons and spaces are layout. */
    public function isFingerprintTrusted(string $sha256Fingerprint): bool
    {
        if ($this->trustedFingerprints === []) {
            return true;
        }

        $candidate = self::hexOnly($sha256Fingerprint);

        foreach ($this->trustedFingerprints as $trusted) {
            if ($candidate !== '' && hash_equals(self::hexOnly($trusted), $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** True when $address, the peer of the TCP connection, is one of the trusted proxies. */
    public function isTrustedProxy(string $address): bool
    {
        foreach ($this->trustedProxies as $proxy) {
            if (NetworkRange::parse($proxy)?->contains($address) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Options for stream_context_create(['ssl' => ...]) on a server socket.
     *
     * @return array<string, bool|int|string>
     */
    public function toStreamContextOptions(): array
    {
        $options = [
            'verify_peer' => $this->peerVerifyMode->verifyPeer(),
            'verify_depth' => $this->verifyDepth,
            'allow_self_signed' => false,
            'capture_peer_cert' => $this->peerVerifyMode->verifyPeer(),
        ];

        $optional = [
            'local_cert' => $this->certFile,
            'local_pk' => $this->keyFile,
            'cafile' => $this->caFile,
            'capath' => $this->caPath,
            'ciphers' => $this->cipherList,
        ];

        return $options + array_filter($optional, static fn(?string $value): bool => $value !== null);
    }

    private static function hexOnly(string $fingerprint): string
    {
        return strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', $fingerprint));
    }
}
