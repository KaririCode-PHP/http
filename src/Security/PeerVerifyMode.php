<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * TLS peer certificate verification modes.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): enum cases are
 * inherently immutable value types.
 *
 * Spec V4.0 §2.1: pure enum.
 *
 * Maps to PHP's STREAM_CRYPTO_METHOD_* and openssl verify_peer
 * options. Controls whether the server validates the client's
 * certificate during the TLS handshake.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 */
enum PeerVerifyMode
{
    /** Do not request or verify client certificates. */
    case None;

    /** Request client certificate but allow connections without one. */
    case Optional;

    /** Require and verify client certificate — reject on failure. */
    case Require;

    /**
     * PHP stream context value for verify_peer.
     */
    public function verifyPeer(): bool
    {
        return match ($this) {
            self::None => false,
            self::Optional, self::Require => true,
        };
    }

    /**
     * Whether to abort the handshake when no client cert is presented.
     */
    public function requireClientCert(): bool
    {
        return $this === self::Require;
    }
}
