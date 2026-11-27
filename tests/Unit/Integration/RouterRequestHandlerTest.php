<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Integration;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Handler\MiddlewareStep;
use KaririCode\Http\Integration\CallableHandler;
use KaririCode\Http\Integration\RouterRequestHandler;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Router\Contract\Router;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RouterRequestHandler::class)]
#[CoversClass(CallableHandler::class)]
#[CoversClass(MiddlewareStep::class)]
final class RouterRequestHandlerTest extends TestCase
{
    /** @var list<ServerRequestInterface> */
    private array $seen = [];

    // ── Matching ─────────────────────────────────────────────────────────

    #[Test]
    public function aMatchedRouteRunsItsHandler(): void
    {
        $routes = (new RouteRegistry())->get('/hello', 'hello');

        $response = $this->handler($routes)->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());
    }

    #[Test]
    public function routeParametersAndTheRouteNameBecomeAttributes(): void
    {
        $routes = (new RouteRegistry())->get('/users/{id}/posts/{slug}', 'record', name: 'user.post');

        $this->handler($routes)->handle(new ServerRequest('GET', '/users/42/posts/hello-world'));

        $request = $this->seen[0];
        self::assertSame('42', $request->getAttribute('id'));
        self::assertSame('hello-world', $request->getAttribute('slug'));
        self::assertSame('user.post', $request->getAttribute('_route'));
    }

    #[Test]
    public function theMethodIsMatchedInUpperCaseAndAnEmptyPathAsTheRoot(): void
    {
        $routes = (new RouteRegistry())->get('/', 'record');

        $response = $this->handler($routes)->handle(new ServerRequest('get', 'https://example.com'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->seen[0]->getAttribute('_route'), 'an unnamed route');
    }

    #[Test]
    public function anUnknownPathIsAJson404(): void
    {
        $response = $this->handler(new RouteRegistry())->handle(new ServerRequest('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Not Found', 'status' => 404], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function aKnownPathWithAnotherMethodIsA405WithAllow(): void
    {
        $routes = (new RouteRegistry())->get('/users', 'record')->post('/users', 'record');

        $response = $this->handler($routes)->handle(new ServerRequest('DELETE', '/users'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, POST', $response->getHeaderLine('Allow'));
        self::assertSame(['error' => 'Method Not Allowed', 'status' => 405], json_decode((string) $response->getBody(), true));
    }

    // ── Middleware ───────────────────────────────────────────────────────

    #[Test]
    public function globalMiddlewareRunsOutsideRouteMiddlewareInOrder(): void
    {
        $routes = (new RouteRegistry())->get('/test', 'record', middleware: ['route-a', 'route-b']);
        $handler = $this->handler($routes, ['route-a' => self::tagging('route-a'), 'route-b' => self::tagging('route-b')]);

        $response = $handler->pipe(self::tagging('global-1'))->pipe(self::tagging('global-2'))->handle(new ServerRequest('GET', '/test'));

        self::assertSame(['route-b', 'route-a', 'global-2', 'global-1'], $response->getHeader('X-Trail'), 'the first piped wraps the rest');
    }

    #[Test]
    public function globalMiddlewareDoesNotRunForUnmatchedRequests(): void
    {
        $handler = $this->handler(new RouteRegistry())->pipe(self::tagging('global'));

        $response = $handler->handle(new ServerRequest('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Trail'));
    }

    #[Test]
    public function theMiddlewareOfTheMatchResultIsWhatRuns(): void
    {
        $route = new RouteEntry(['GET'], '/x', 'record');
        $router = $this->createStub(Router::class);
        $router->method('match')->willReturn(new MatchResult($route, [], ['named']));
        $handler = new RouterRequestHandler($router, fn(mixed $target): mixed => $target === 'named' ? self::tagging('named') : $this->resolve($target));

        $response = $handler->handle(new ServerRequest('GET', '/x'));

        self::assertSame(['named'], $response->getHeader('X-Trail'));
    }

    #[Test]
    public function middlewareThatDoesNotResolveFailsInsteadOfBeingSkipped(): void
    {
        $routes = (new RouteRegistry())->get('/admin', 'record', middleware: ['auth']);
        $handler = new RouterRequestHandler($routes, fn(mixed $target): mixed => $target === 'auth' ? 'not middleware' : $this->resolve($target));

        try {
            $handler->handle(new ServerRequest('GET', '/admin'));
            self::fail('an unresolvable middleware was skipped');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(HttpCode::UnresolvableMiddleware, $e->errorCode);
            self::assertStringContainsString('"auth"', $e->getMessage());
        }

        self::assertSame([], $this->seen, 'the handler never ran');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, MiddlewareInterface> $middleware middleware by name
     */
    private function handler(Router $routes, array $middleware = []): RouterRequestHandler
    {
        return new RouterRequestHandler($routes, fn(mixed $target): mixed => is_string($target) && isset($middleware[$target]) ? $middleware[$target] : $this->resolve($target));
    }

    /** Route handlers by name: "record" keeps the request, anything else answers its own name. */
    private function resolve(mixed $target): \Closure
    {
        if ($target === 'record') {
            return function (ServerRequestInterface $request): ResponseInterface {
                $this->seen[] = $request;

                return new Response(200);
            };
        }

        return static fn(): string => is_string($target) ? $target : '';
    }

    /** Adds its tag to X-Trail on the way out. */
    private static function tagging(string $tag): MiddlewareInterface
    {
        return new readonly class ($tag) implements MiddlewareInterface {
            public function __construct(private string $tag) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withAddedHeader('X-Trail', $this->tag);
            }
        };
    }
}
