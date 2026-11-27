<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\SystemClock;
use KaririCode\Http\Security\TlsConfiguration;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces the client-certificate policy of a TlsConfiguration and puts
 * the verified certificate in the request attribute "client_certificate".
 * The certificate is believed only from a source the client cannot write:
 * the TLS variables of the web server (SSL_CLIENT_VERIFY and the other
 * SSL_CLIENT_* server parameters), or the X-SSL-Client-* headers when the
 * TCP peer (REMOTE_ADDR) is one of the configured trusted proxies. From any
 * other peer those headers are removed before the request goes on, so no
 * handler can read a forged value. Verification must read SUCCESS: a
 * missing, NONE, GENEROUS or FAILED result is not a verified certificate.
 * With pinned fingerprints a certificate without a fingerprint is refused;
 * the validity period is checked against the injected clock when the dates
 * are known. Refusals are 403 with a JSON body.
 *
 * ARFA 1.3: P2 (a gate in front of the application). Spec V4.3.3: final,
 * stateless apart from its policy and clock.
 *
 * Preconditions: TLS terminates at the web server, which verifies the chain
 * against the client CA and exports SSL_CLIENT_* (mod_ssl +StdEnvVars
 * +ExportCertData; nginx fastcgi_param), or at a proxy listed in
 * $config->trustedProxies that overwrites the X-SSL-Client-* headers.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: reads the clock.
 *
 * Example:
 *
 *     $app->pipe(new ClientCertificateMiddleware(
 *         TlsConfiguration::mutual($cert, $key, $clientCa)->withTrustedProxies(['10.0.0.0/8']),
 *     ));
 *
 * References: RFC 8446 §4.4.2 (client Certificate); Apache mod_ssl
 * environment variables; nginx ngx_http_ssl_module ($ssl_client_verify);
 * OWASP Transport Layer Security Cheat Sheet; ADR-007.
 *
 * @see https://httpd.apache.org/docs/current/mod/mod_ssl.html#envvars
 */
final class ClientCertificateMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE_KEY = 'client_certificate';

    private const array PROXY_HEADERS = [
        'x-ssl-client-verify', 'x-ssl-client-dn', 'x-client-dn', 'x-ssl-client-issuer', 'x-client-issuer-dn',
        'x-ssl-client-serial', 'x-ssl-client-sha256', 'x-ssl-client-fingerprint', 'x-ssl-client-cert',
        'x-ssl-client-notbefore', 'x-ssl-client-notafter',
    ];

    public function __construct(
        private readonly TlsConfiguration $config,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $server = $request->getServerParams();
        $fromProxy = !isset($server['SSL_CLIENT_VERIFY']) && $this->config->isTrustedProxy(self::text($server['REMOTE_ADDR'] ?? null));
        $request = $fromProxy ? $request : self::withoutProxyHeaders($request);
        $verify = strtoupper($fromProxy ? $request->getHeaderLine('X-SSL-Client-Verify') : self::text($server['SSL_CLIENT_VERIFY'] ?? null));

        if ($verify !== 'SUCCESS') {
            return $this->unverified($verify, $request, $handler);
        }

        $certificate = $fromProxy ? ClientCertificate::fromProxyHeaders(self::proxyHeaders($request)) : ClientCertificate::fromServerParams($server);
        $refusal = $this->refusal($certificate);

        if ($refusal === null) {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE_KEY, $certificate));
        }

        return $refusal === '' ? $handler->handle($request) : self::forbidden($refusal);
    }

    /** No certificate (NONE or nothing): allowed unless required. Any other result is a failed verification. */
    private function unverified(string $verify, ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($verify !== '' && $verify !== 'NONE') {
            return self::forbidden('Client certificate verification failed');
        }

        return $this->config->peerVerifyMode->requireClientCert() ? self::forbidden('Client certificate required') : $handler->handle($request);
    }

    /**
     * Why the certificate is refused; '' to go on without one (Optional
     * mode, unreadable certificate); null when it passes.
     */
    private function refusal(ClientCertificate $certificate): ?string
    {
        if ($certificate->isEmpty()) {
            return $this->config->peerVerifyMode->requireClientCert() ? 'Client certificate could not be parsed' : '';
        }

        return match (true) {
            !$certificate->isWithinValidityPeriod($this->clock->now()) => 'Client certificate is expired or not yet valid',
            !$this->config->isDnAllowed($certificate->subjectDn) => 'Client certificate DN not authorized',
            $this->config->hasFingerprintPinning() && !$this->config->isFingerprintTrusted($certificate->fingerprint) => 'Client certificate fingerprint not trusted',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function proxyHeaders(ServerRequestInterface $request): array
    {
        $headers = [];

        foreach (self::PROXY_HEADERS as $name) {
            $value = $request->getHeaderLine($name);

            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function withoutProxyHeaders(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach (self::PROXY_HEADERS as $name) {
            if ($request->hasHeader($name)) {
                $request = $request->withoutHeader($name);
            }
        }

        return $request;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function forbidden(string $message): ResponseInterface
    {
        return new Response(403, headers: ['Content-Type' => 'application/json'], body: Stream::fromString(json_encode(['error' => 'certificate_required', 'message' => $message], JSON_THROW_ON_ERROR)));
    }
}
