<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Middleware\CorsConfiguration;
use KaririCode\Http\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddlewareTest extends TestCase
{
    // ── Non-CORS Requests ────────────────────────────────────────────────

    public function testNonCorsRequestPassesThroughUnmodified(): void
    {
        $middleware = new CorsMiddleware();
        $request = new ServerRequest('GET', 'https://example.com/api/users');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginPassesThroughWithoutCorsHeaders(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://allowed.com']);
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('GET', 'https://evil.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    // ── Simple/Actual CORS Requests ──────────────────────────────────────

    public function testSimpleGetWithWildcardOrigin(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->requestWithOrigin('GET', 'https://app.example.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testSimpleGetWithExplicitOrigin(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://app.com']);
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('GET', 'https://app.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('https://app.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testActualRequestWithCredentials(): void
    {
        $config = CorsConfiguration::singleOrigin('https://spa.example.com');
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('POST', 'https://spa.example.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('https://spa.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testExposedHeadersIncludedInActualResponse(): void
    {
        $config = (new CorsConfiguration())->withExposedHeaders(['X-Request-Id', 'X-RateLimit-Remaining']);
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('GET', 'https://app.example.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(
            'X-Request-Id, X-RateLimit-Remaining',
            $response->getHeaderLine('Access-Control-Expose-Headers'),
        );
    }

    public function testWildcardOriginWithCredentialsEchosSpecificOrigin(): void
    {
        $config = (new CorsConfiguration())->withCredentials(true);
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('GET', 'https://any.example.com');

        $response = $middleware->process($request, $this->okHandler());

        // Spec: when credentials=true, must NOT return '*'
        self::assertSame('https://any.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    // ── Preflight Requests ───────────────────────────────────────────────

    public function testPreflightReturns204WithCorsHeaders(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->preflightRequest('https://app.example.com', 'POST');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testPreflightIncludesAllowedHeaders(): void
    {
        $config = new CorsConfiguration(allowedHeaders: ['Content-Type', 'Authorization']);
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://app.example.com', 'POST', 'Content-Type');

        $response = $middleware->process($request, $this->neverCalledHandler());

        $allowedHeaders = $response->getHeaderLine('Access-Control-Allow-Headers');
        self::assertStringContainsString('Content-Type', $allowedHeaders);
        self::assertStringContainsString('Authorization', $allowedHeaders);
    }

    public function testPreflightWithWildcardHeadersEchosRequestHeaders(): void
    {
        $config = CorsConfiguration::permissive();
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://app.example.com', 'PUT', 'X-Custom, X-Another');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame('X-Custom, X-Another', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testPreflightWithDisallowedMethodReturns204WithoutCorsHeaders(): void
    {
        $config = new CorsConfiguration(allowedMethods: ['GET', 'POST']);
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://app.example.com', 'DELETE');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightWithDisallowedOriginPassesThrough(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://allowed.com']);
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://evil.com', 'GET');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightWithZeroMaxAgeOmitsHeader(): void
    {
        $config = (new CorsConfiguration())->withMaxAge(0);
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://app.example.com', 'POST');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertFalse($response->hasHeader('Access-Control-Max-Age'));
    }

    public function testPreflightWithCredentialsEchosSpecificOrigin(): void
    {
        $config = CorsConfiguration::singleOrigin('https://spa.example.com');
        $middleware = new CorsMiddleware($config);
        $request = $this->preflightRequest('https://spa.example.com', 'PUT');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame('https://spa.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    // ── Vary Header ──────────────────────────────────────────────────────

    public function testVaryOriginNotAddedForWildcardWithoutCredentials(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->requestWithOrigin('GET', 'https://app.example.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertStringNotContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testVaryOriginAddedForExplicitOriginList(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://a.com', 'https://b.com']);
        $middleware = new CorsMiddleware($config);
        $request = $this->requestWithOrigin('GET', 'https://a.com');

        $response = $middleware->process($request, $this->okHandler());

        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    // ── OPTIONS Without ACRM (not preflight) ─────────────────────────────

    public function testOptionsWithoutAccessControlRequestMethodIsNotPreflight(): void
    {
        $middleware = new CorsMiddleware();
        $request = (new ServerRequest('OPTIONS', 'https://example.com/api'))
            ->withHeader('Origin', 'https://app.example.com');

        $response = $middleware->process($request, $this->okHandler());

        // Should be treated as actual request, not preflight
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function requestWithOrigin(string $method, string $origin): ServerRequestInterface
    {
        return (new ServerRequest($method, 'https://example.com/api/data'))
            ->withHeader('Origin', $origin);
    }

    private function preflightRequest(string $origin, string $requestMethod, string $requestHeaders = ''): ServerRequestInterface
    {
        $request = (new ServerRequest('OPTIONS', 'https://example.com/api/data'))
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', $requestMethod);

        if ($requestHeaders !== '') {
            $request = $request->withHeader('Access-Control-Request-Headers', $requestHeaders);
        }

        return $request;
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
                throw new \RuntimeException('Handler should not be called for preflight');
            }
        };
    }
}
