<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Middleware\TrailingSlashMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TrailingSlashMiddlewareTest extends TestCase
{
    public function testRedirectsGetWithTrailingSlash(): void
    {
        $middleware = new TrailingSlashMiddleware(redirect: true);
        $request = new ServerRequest('GET', 'https://example.com/users/');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertStringContainsString('/users', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('/users/', $response->getHeaderLine('Location'));
    }

    public function testSilentStripForPostWithTrailingSlash(): void
    {
        $capturedPath = null;
        $handler = $this->capturingHandler($capturedPath);

        $middleware = new TrailingSlashMiddleware(redirect: true);
        $request = new ServerRequest('POST', 'https://example.com/api/users/');

        $middleware->process($request, $handler);

        self::assertSame('/api/users', $capturedPath);
    }

    public function testNoRedirectModeStripsForGet(): void
    {
        $capturedPath = null;
        $handler = $this->capturingHandler($capturedPath);

        $middleware = new TrailingSlashMiddleware(redirect: false);
        $request = new ServerRequest('GET', 'https://example.com/path/');

        $middleware->process($request, $handler);

        self::assertSame('/path', $capturedPath);
    }

    public function testRootPathUnchanged(): void
    {
        $capturedPath = null;
        $handler = $this->capturingHandler($capturedPath);

        $middleware = new TrailingSlashMiddleware();
        $request = new ServerRequest('GET', 'https://example.com/');

        $middleware->process($request, $handler);

        self::assertSame('/', $capturedPath);
    }

    public function testPathWithoutTrailingSlashPassesThrough(): void
    {
        $capturedPath = null;
        $handler = $this->capturingHandler($capturedPath);

        $middleware = new TrailingSlashMiddleware();
        $request = new ServerRequest('GET', 'https://example.com/users');

        $middleware->process($request, $handler);

        self::assertSame('/users', $capturedPath);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function neverCalledHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Handler should not be called');
            }
        };
    }

    private function capturingHandler(?string &$capturedPath): RequestHandlerInterface
    {
        return new class ($capturedPath) implements RequestHandlerInterface {
            public function __construct(private ?string &$path) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->path = $request->getUri()->getPath();
                return new Response(200);
            }
        };
    }
}
