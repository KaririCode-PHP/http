<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

/**
 * How a server treats client certificates: None never asks for one,
 * Optional asks and accepts a client without one, Require refuses a client
 * without a verified certificate. It decides PHP's verify_peer option and
 * whether ClientCertificateMiddleware answers 403 when no certificate
 * arrives.
 *
 * ARFA 1.3: P1 (immutable cases). Spec V4.3.3: pure enum.
 *
 * Preconditions: none.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     PeerVerifyMode::Require->requireClientCert(); // true
 *     PeerVerifyMode::Optional->verifyPeer();       // true
 *
 * References: PHP manual, SSL context options; RFC 8446 §4.3.2
 * (CertificateRequest); ADR-007.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 */
enum PeerVerifyMode
{
    case None;

    case Optional;

    case Require;

    public function verifyPeer(): bool
    {
        return $this !== self::None;
    }

    public function requireClientCert(): bool
    {
        return $this === self::Require;
    }
}
