<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * Immutable TLS/mTLS configuration shared across protocol layers.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): all with* methods
 * return new instances. Static factories provide common presets.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): this configuration serves
 * HTTP (reverse-proxy headers), WebSocket (stream_context), and
 * gRPC (interceptor metadata) — each protocol adapter extracts
 * what it needs from the same value object.
 *
 * Spec V4.0: final readonly class.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 * @see https://datatracker.ietf.org/doc/html/rfc8446 TLS 1.3
 */
final readonly class TlsConfiguration
{
    /**
     * @param string|null      $certFile       Server certificate path (PEM)
     * @param string|null      $keyFile        Server private key path (PEM)
     * @param string|null      $caFile         CA bundle for client cert verification (PEM)
     * @param string|null      $caPath         Directory of CA certificates (c_rehash'd)
     * @param PeerVerifyMode   $peerVerifyMode Client certificate requirement level
     * @param int              $verifyDepth    Maximum certificate chain depth
     * @param string|null      $cipherList     OpenSSL cipher list (TLS 1.2)
     * @param string|null      $cipherSuites   TLS 1.3 ciphersuites
     * @param list<string>     $allowedDns     Allowed client certificate Subject DNs (empty = any)
     * @param list<string>     $trustedFingerprints SHA-256 fingerprints of pinned client certs
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
    ) {
    }

    // ── Static Factories ─────────────────────────────────────────────────

    /**
     * Server TLS only — no client certificate required.
     * Standard HTTPS: server proves its identity, client is anonymous.
     */
    public static function serverOnly(string $certFile, string $keyFile): self
    {
        return new self(
            certFile: $certFile,
            keyFile: $keyFile,
        );
    }

    /**
     * Full mutual TLS — both server and client present certificates.
     * The CA file verifies client certificates; connection is rejected
     * if the client cert is missing or untrusted.
     */
    public static function mutual(string $certFile, string $keyFile, string $caFile): self
    {
        return new self(
            certFile: $certFile,
            keyFile: $keyFile,
            caFile: $caFile,
            peerVerifyMode: PeerVerifyMode::Require,
        );
    }

    /**
     * Optional client cert — present if available, proceed without.
     * Useful for APIs that offer enhanced access with client certs
     * but still allow anonymous requests.
     */
    public static function optionalClient(string $certFile, string $keyFile, string $caFile): self
    {
        return new self(
            certFile: $certFile,
            keyFile: $keyFile,
            caFile: $caFile,
            peerVerifyMode: PeerVerifyMode::Optional,
        );
    }

    // ── Wither Methods ───────────────────────────────────────────────────

    public function withPeerVerifyMode(PeerVerifyMode $mode): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $mode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints);
    }

    public function withVerifyDepth(int $depth): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $depth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints);
    }

    public function withCipherList(string $cipherList): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $cipherList, $this->cipherSuites, $this->allowedDns, $this->trustedFingerprints);
    }

    public function withCipherSuites(string $cipherSuites): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $cipherSuites, $this->allowedDns, $this->trustedFingerprints);
    }

    /** @param list<string> $dns */
    public function withAllowedDns(array $dns): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $dns, $this->trustedFingerprints);
    }

    /** @param list<string> $fingerprints SHA-256 fingerprints */
    public function withTrustedFingerprints(array $fingerprints): self
    {
        return new self($this->certFile, $this->keyFile, $this->caFile, $this->caPath, $this->peerVerifyMode, $this->verifyDepth, $this->cipherList, $this->cipherSuites, $this->allowedDns, $fingerprints);
    }

    // ── Query Methods ────────────────────────────────────────────────────

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
        if ($this->allowedDns === []) {
            return true;
        }

        return in_array($subjectDn, $this->allowedDns, true);
    }

    public function isFingerprintTrusted(string $sha256Fingerprint): bool
    {
        if ($this->trustedFingerprints === []) {
            return true;
        }

        $normalized = strtolower(str_replace(':', '', $sha256Fingerprint));

        foreach ($this->trustedFingerprints as $trusted) {
            if (strtolower(str_replace(':', '', $trusted)) === $normalized) {
                return true;
            }
        }

        return false;
    }

    // ── Stream Context Builder ───────────────────────────────────────────

    /**
     * Builds PHP stream context SSL options.
     *
     * Used by WebSocket server and any PHP-native TLS socket.
     * Reverse-proxy setups (nginx/Caddy → PHP-FPM) do not use this;
     * they rely on ClientCertificateMiddleware parsing proxy headers.
     *
     * @return array<string, mixed> Options for stream_context_create(['ssl' => ...])
     */
    public function toStreamContextOptions(): array
    {
        $options = [
            'verify_peer' => $this->peerVerifyMode->verifyPeer(),
            'verify_depth' => $this->verifyDepth,
            'allow_self_signed' => false,
        ];

        if ($this->certFile !== null) {
            $options['local_cert'] = $this->certFile;
        }

        if ($this->keyFile !== null) {
            $options['local_pk'] = $this->keyFile;
        }

        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }

        if ($this->caPath !== null) {
            $options['capath'] = $this->caPath;
        }

        if ($this->cipherList !== null) {
            $options['ciphers'] = $this->cipherList;
        }

        if ($this->peerVerifyMode === PeerVerifyMode::Require) {
            $options['capture_peer_cert'] = true;
        }

        return $options;
    }
}
