<?php

declare(strict_types=1);

namespace KaririCode\Http\Integration;

use Closure;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Handler\MiddlewareStep;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use KaririCode\Router\Contract\Router;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 request handler backed by kariricode/router: it matches the
 * method and path, answers 404 or 405 (with Allow) as JSON when nothing
 * matches, adds the route parameters and `_route` as request attributes,
 * and runs the global middleware, then the route's middleware, in front of
 * the route handler. The route's middleware names go through the
 * resolver; a name that does not resolve to a PSR-15 middleware throws
 * instead of being skipped, so a guard such as authentication cannot drop
 * out of a route.
 *
 * ARFA 1.3: P2 (route → middleware → handler) and P4 (routing stays in the
 * router). Spec V4.3.3: final; pipe() is for bootstrap.
 *
 * Preconditions: $resolver turns a route handler or a middleware name into
 * a callable or a MiddlewareInterface (a container lookup, typically).
 * Global middleware runs only for matched routes: CORS preflights for paths
 * without an OPTIONS route belong in a MiddlewareDispatcher placed in front
 * of this handler.
 *
 * Thread-safety: handle() keeps no state; pipe() changes the global queue.
 *
 * Side effects: those of the resolver, the middleware and the handler.
 *
 * Example:
 *
 *     $handler = new RouterRequestHandler($routes, static fn(mixed $target): mixed => $container->get(...));
 *     $response = $handler->handle(ServerRequest::fromGlobals());
 *
 * References: kariricode/router Router and MatchResult; PSR-15; RFC 9110
 * §15.5.5 (404) and §15.5.6 (405, Allow); ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-15.5.6
 */
final class RouterRequestHandler implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware = [];

    /**
     * @param Closure(mixed): mixed $resolver route handler or middleware name => callable or MiddlewareInterface
     */
    public function __construct(
        private readonly Router $router,
        private readonly Closure $resolver,
    ) {}

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        try {
            $result = $this->router->match($request->getMethod(), $path === '' ? '/' : $path);
        } catch (RouteNotFoundException) {
            return self::error(404, 'Not Found');
        } catch (MethodNotAllowedException $e) {
            return self::error(405, 'Method Not Allowed')->withHeader('Allow', implode(', ', $e->allowedMethods));
        }

        foreach ($result->parameters->all() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $request = $request->withAttribute('_route', $result->routeName());
        $handler = new CallableHandler(($this->resolver)($result->handler()));
        $middleware = [...$this->globalMiddleware, ...$this->resolveAll($result->middleware)];

        return (new MiddlewareStep($middleware, $handler))->handle($request);
    }

    /**
     * @param list<string> $names
     *
     * @return list<MiddlewareInterface>
     */
    private function resolveAll(array $names): array
    {
        $resolved = [];

        foreach ($names as $name) {
            $resolved[] = self::middleware(($this->resolver)($name), $name);
        }

        return $resolved;
    }

    private static function middleware(mixed $resolved, string $name): MiddlewareInterface
    {
        return $resolved instanceof MiddlewareInterface ? $resolved : throw InvalidConfigurationException::unresolvableMiddleware($name);
    }

    private static function error(int $status, string $message): ResponseInterface
    {
        return new Response($status, headers: ['Content-Type' => 'application/json; charset=utf-8'], body: Stream::fromString(json_encode(['error' => $message, 'status' => $status], JSON_THROW_ON_ERROR)));
    }
}
