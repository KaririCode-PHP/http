<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\TlsConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Extracts and validates client certificates from reverse-proxy headers.
 *
 * When PHP runs behind nginx/Caddy/HAProxy with mTLS termination,
 * the proxy forwards certificate details via X-SSL-Client-* headers.
 * This middleware parses those headers into a {@see ClientCertificate}
 * value object, validates against the configured TLS policy (DN
 * allowlist, fingerprint pinning, expiration), and injects the
 * certificate as a request attribute for downstream handlers.
 *
 * Request attribute key: 'client_certificate'
 *
 * Responds with 403 Forbidden when:
 *   - PeerVerifyMode::Require and no client cert header is present
 *   - Proxy reports verification failure (X-SSL-Client-Verify ≠ SUCCESS)
 *   - Subject DN not in allowedDns list
 *   - Fingerprint not in trustedFingerprints list
 *   - Certificate is expired or not yet valid
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): middleware pipeline stage.
 *
 * Spec V4.0: final class, no inheritance.
 *
 * @see https://nginx.org/en/docs/http/ngx_http_ssl_module.html
 * @see https://caddyserver.com/docs/caddyfile/directives/tls
 */
final class ClientCertificateMiddleware implements MiddlewareInterface
{
    /** Request attribute key for the injected certificate. */
    public const ATTRIBUTE_KEY = 'client_certificate';

    /** Header set by nginx/Caddy indicating proxy-level verification result. */
    private const HEADER_VERIFY = 'X-SSL-Client-Verify';

    public function __construct(
        private readonly TlsConfiguration $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $verifyResult = $request->getHeaderLine(self::HEADER_VERIFY);
        $hasCert = $this->hasClientCertHeaders($request);

        // No client cert presented
        if (!$hasCert) {
            if ($this->config->peerVerifyMode->requireClientCert()) {
                return self::forbidden('Client certificate required');
            }

            // Optional mode — proceed without cert
            return $handler->handle($request);
        }

        // Proxy-level verification failed
        if ($verifyResult !== '' && strtoupper($verifyResult) !== 'SUCCESS') {
            return self::forbidden('Client certificate verification failed');
        }

        $cert = ClientCertificate::fromProxyHeaders($this->extractHeaders($request));

        if ($cert->isEmpty()) {
            if ($this->config->peerVerifyMode->requireClientCert()) {
                return self::forbidden('Client certificate could not be parsed');
            }

            return $handler->handle($request);
        }

        // Validity period check
        if (!$cert->isWithinValidityPeriod()) {
            return self::forbidden('Client certificate is expired or not yet valid');
        }

        // DN restriction
        if ($this->config->hasDnRestriction() && !$this->config->isDnAllowed($cert->subjectDn)) {
            return self::forbidden('Client certificate DN not authorized');
        }

        // Fingerprint pinning
        if ($this->config->hasFingerprintPinning() && $cert->fingerprint !== '') {
            if (!$this->config->isFingerprintTrusted($cert->fingerprint)) {
                return self::forbidden('Client certificate fingerprint not trusted');
            }
        }

        // Inject into request and proceed
        $request = $request->withAttribute(self::ATTRIBUTE_KEY, $cert);

        return $handler->handle($request);
    }

    // ── Internal Helpers ─────────────────────────────────────────────────

    private function hasClientCertHeaders(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('X-SSL-Client-DN')
            || $request->hasHeader('X-Client-DN')
            || $request->hasHeader('X-SSL-Client-Cert');
    }

    /**
     * @return array<string, string> Lowercase header name → value
     */
    private function extractHeaders(ServerRequestInterface $request): array
    {
        $relevant = [
            'x-ssl-client-dn', 'x-client-dn',
            'x-ssl-client-issuer', 'x-client-issuer-dn',
            'x-ssl-client-serial',
            'x-ssl-client-sha256', 'x-ssl-client-fingerprint',
            'x-ssl-client-cert',
            'x-ssl-client-notbefore', 'x-ssl-client-notafter',
        ];

        $headers = [];
        foreach ($relevant as $name) {
            $value = $request->getHeaderLine($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function forbidden(string $message): ResponseInterface
    {
        return new Response(
            statusCode: 403,
            headers: ['Content-Type' => 'application/json'],
            body: Stream::fromString(json_encode([
                'error' => 'certificate_required',
                'message' => $message,
            ], JSON_THROW_ON_ERROR)),
        );
    }
}
