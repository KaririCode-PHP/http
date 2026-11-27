<?php

declare(strict_types=1);

namespace KaririCode\Http\Security;

use Psr\Clock\ClockInterface;

/**
 * The wall clock ClientCertificateMiddleware uses by default to check a
 * certificate's validity period; tests and replay tools pass a frozen
 * PSR-20 clock instead, so no check depends on ambient time (Spec V4.3.3
 * §19.2). The instant is in UTC whatever date.timezone says, so the same
 * moment reads the same in logs from every host.
 *
 * ARFA 1.3: P4 (time arrives through PSR-20). Spec V4.3.3: stateless
 * service, final readonly.
 *
 * Preconditions: none.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: reads the system clock.
 *
 * Example:
 *
 *     $middleware = new ClientCertificateMiddleware($tls, clock: new SystemClock());
 *
 * References: PSR-20 Clock; KaririCode Spec V4.3.3 §19.2.
 *
 * @see https://www.php-fig.org/psr/psr-20/
 */
final readonly class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
