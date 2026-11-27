<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Middleware\ClientCertificateMiddleware;
use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\PeerVerifyMode;
use KaririCode\Http\Security\TlsConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ClientCertificateMiddlewareTest extends TestCase
{
    // ── No Certificate Headers ───────────────────────────────────────────

    public function testNoCertHeadersWithRequireMode403(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = new ServerRequest('GET', 'https://api.example.com/data');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('required', (string) $response->getBody());
    }

    public function testNoCertHeadersWithOptionalModePassesThrough(): void
    {
        $config = TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = new ServerRequest('GET', 'https://api.example.com/data');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNoCertHeadersWithNoneModePassesThrough(): void
    {
        $config = new TlsConfiguration();
        $middleware = new ClientCertificateMiddleware($config);

        $request = new ServerRequest('GET', 'https://api.example.com/data');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Proxy Verification Failure ───────────────────────────────────────

    public function testProxyVerificationFailureReturns403(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=evil')
            ->withHeader('X-SSL-Client-Verify', 'FAILED');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('verification failed', (string) $response->getBody());
    }

    public function testProxyVerificationSuccessProceeds(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = $this->validCertRequest()
            ->withHeader('X-SSL-Client-Verify', 'SUCCESS');

        $response = $middleware->process($request, $this->capturingHandler($captured));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(ClientCertificate::class, $captured);
    }

    // ── DN Restriction ───────────────────────────────────────────────────

    public function testAllowedDnPassesThrough(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem')
            ->withAllowedDns(['CN=api-gateway,O=Corp']);
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=api-gateway,O=Corp')
            ->withHeader('X-SSL-Client-NotBefore', '2024-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z');

        $response = $middleware->process($request, $this->capturingHandler($captured));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('CN=api-gateway,O=Corp', $captured->subjectDn);
    }

    public function testDisallowedDnReturns403(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem')
            ->withAllowedDns(['CN=api-gateway,O=Corp']);
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=evil-service,O=Hacker')
            ->withHeader('X-SSL-Client-NotBefore', '2024-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('DN not authorized', (string) $response->getBody());
    }

    // ── Fingerprint Pinning ──────────────────────────────────────────────

    public function testTrustedFingerprintPassesThrough(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem')
            ->withTrustedFingerprints(['aabbccdd']);
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=service-a')
            ->withHeader('X-SSL-Client-SHA256', 'aabbccdd')
            ->withHeader('X-SSL-Client-NotBefore', '2024-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUntrustedFingerprintReturns403(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem')
            ->withTrustedFingerprints(['aabbccdd']);
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=service-a')
            ->withHeader('X-SSL-Client-SHA256', '11223344')
            ->withHeader('X-SSL-Client-NotBefore', '2024-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('fingerprint not trusted', (string) $response->getBody());
    }

    // ── Certificate Validity ─────────────────────────────────────────────

    public function testExpiredCertificateReturns403(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=expired-svc')
            ->withHeader('X-SSL-Client-NotBefore', '2020-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2021-12-31T23:59:59Z');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('expired', (string) $response->getBody());
    }

    // ── Attribute Injection ──────────────────────────────────────────────

    public function testInjectsClientCertificateAsRequestAttribute(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = $this->validCertRequest();

        $middleware->process($request, $this->capturingHandler($captured));

        self::assertInstanceOf(ClientCertificate::class, $captured);
        self::assertSame('CN=service-a,O=Corp', $captured->subjectDn);
    }

    public function testAttributeKeyIsAccessible(): void
    {
        self::assertSame('client_certificate', ClientCertificateMiddleware::ATTRIBUTE_KEY);
    }

    // ── Response Format ──────────────────────────────────────────────────

    public function test403ResponseIsJson(): void
    {
        $config = TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
        $middleware = new ClientCertificateMiddleware($config);

        $request = new ServerRequest('GET', 'https://api.example.com/data');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('certificate_required', $body['error']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function validCertRequest(): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://api.example.com/data'))
            ->withHeader('X-SSL-Client-DN', 'CN=service-a,O=Corp')
            ->withHeader('X-SSL-Client-Issuer', 'CN=Root CA,O=Corp')
            ->withHeader('X-SSL-Client-SHA256', 'aabbccdd')
            ->withHeader('X-SSL-Client-NotBefore', '2024-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z');
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private function neverCalledHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Handler should not be called');
            }
        };
    }

    /**
     * @param ClientCertificate|null &$captured Receives the injected certificate
     */
    private function capturingHandler(?ClientCertificate &$captured): RequestHandlerInterface
    {
        return new class ($captured) implements RequestHandlerInterface {
            public function __construct(private ?ClientCertificate &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY);
                return new Response(200);
            }
        };
    }
}
