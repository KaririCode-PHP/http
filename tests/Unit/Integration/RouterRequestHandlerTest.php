<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Integration;

use KaririCode\Http\Integration\RouterRequestHandler;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use KaririCode\Router\Contract\Router;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests RouterRequestHandler — the PSR-15 handler backed by Router V4.1.
 *
 * Requires kariricode/router as dev dependency.
 */
final class RouterRequestHandlerTest extends TestCase
{
    // ── Basic Handler Resolution ─────────────────────────────────────────

    public function testDispatchesCallableHandler(): void
    {
        $route = new RouteEntry(['GET'], '/hello', 'hello_handler', name: 'hello');
        $result = new MatchResult($route);

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => new Response(200);

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInjectsRouteParametersAsAttributes(): void
    {
        $route = new RouteEntry(['GET'], '/users/{id}', 'user_show', name: 'user.show');
        $result = new MatchResult($route, ['id' => '42']);

        $captured = null;
        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static function (ServerRequestInterface $request) use (&$captured) {
            $captured = [
                'id' => $request->getAttribute('id'),
                '_route' => $request->getAttribute('_route'),
            ];
            return new Response(200);
        };

        $handler = new RouterRequestHandler($router, $resolver);
        $handler->handle(new ServerRequest('GET', '/users/42'));

        self::assertSame('42', $captured['id']);
        self::assertSame('user.show', $captured['_route']);
    }

    // ── Handler Return Value Conversion ──────────────────────────────────

    public function testStringReturnBecomesHtmlResponse(): void
    {
        $route = new RouteEntry(['GET'], '/page', 'page_handler', name: 'page');
        $result = new MatchResult($route);

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => '<h1>Hello</h1>';

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('GET', '/page'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function testArrayReturnBecomesJsonResponse(): void
    {
        $route = new RouteEntry(['GET'], '/api', 'api_handler', name: 'api');
        $result = new MatchResult($route);

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => ['status' => 'ok', 'items' => [1, 2, 3]];

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('GET', '/api'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $data['status']);
        self::assertSame([1, 2, 3], $data['items']);
    }

    public function testNullReturnBecomes204(): void
    {
        $route = new RouteEntry(['DELETE'], '/item', 'delete_handler', name: 'delete');
        $result = new MatchResult($route);

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => null;

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('DELETE', '/item'));

        self::assertSame(204, $response->getStatusCode());
    }

    public function testResponseInterfaceReturnPassesThrough(): void
    {
        $route = new RouteEntry(['GET'], '/custom', 'custom_handler', name: 'custom');
        $result = new MatchResult($route);

        $customResponse = (new Response(201))
            ->withHeader('X-Custom', 'value')
            ->withBody(Stream::fromString('created'));

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => $customResponse;

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('GET', '/custom'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('value', $response->getHeaderLine('X-Custom'));
        self::assertSame('created', (string) $response->getBody());
    }

    // ── Error Handling ───────────────────────────────────────────────────

    public function testRouteNotFoundReturns404(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('match')->willThrowException(
            new RouteNotFoundException('GET', '/missing'),
        );
        $resolver = static fn (string $name) => static fn () => null;

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Not Found', $body['error']);
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('match')->willThrowException(
            new MethodNotAllowedException('DELETE', '/users', ['GET', 'POST']),
        );
        $resolver = static fn (string $name) => static fn () => null;

        $handler = new RouterRequestHandler($router, $resolver);
        $response = $handler->handle(new ServerRequest('DELETE', '/users'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    // ── Global Middleware ────────────────────────────────────────────────

    public function testGlobalMiddlewareApplied(): void
    {
        $route = new RouteEntry(['GET'], '/test', 'test_handler', name: 'test');
        $result = new MatchResult($route);

        $router = $this->createRouterMock($result);
        $resolver = static fn (string $name) => static fn () => new Response(200);

        $globalMiddleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Global', 'applied');
            }
        };

        $handler = new RouterRequestHandler($router, $resolver);
        $handler->pipe($globalMiddleware);

        $response = $handler->handle(new ServerRequest('GET', '/test'));

        self::assertSame('applied', $response->getHeaderLine('X-Global'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createRouterMock(MatchResult $result): Router
    {
        $mock = $this->createMock(Router::class);
        $mock->method('match')->willReturn($result);

        return $mock;
    }
}
