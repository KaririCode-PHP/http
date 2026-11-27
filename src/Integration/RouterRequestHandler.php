<?php

declare(strict_types=1);

namespace KaririCode\Http\Integration;

use KaririCode\Http\Handler\MiddlewareDispatcher;
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
 * PSR-15 RequestHandler backed by KaririCode Router V4.1.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): composes global and
 * route-specific middleware into a single pipeline per request.
 * ARFA 1.3 P4 (Protocol Agnosticism): receives a PSR-7 request
 * but delegates to the Router's string-based match(), keeping the
 * Router free of HTTP types.
 *
 * Lifecycle:
 *   1. Extract (method, uri) from ServerRequest
 *   2. Router::match() → MatchResult (route, params, middleware)
 *   3. Inject route params as request attributes
 *   4. Build middleware pipeline (global + route-specific)
 *   5. Execute handler, coerce return value to ResponseInterface
 *
 * Handler resolution is delegated to a callable (typically DI container).
 * Spec V4.0: final class.
 *
 * @see https://www.php-fig.org/psr/psr-15/#22-psrhttpservermiddlewareinterface PSR-15 Middleware
 */
final class RouterRequestHandler implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware = [];

    /**
     * @param Router   $router         KaririCode Router instance
     * @param callable $handlerResolver Resolves handler definition → callable
     *                                  Signature: (string|callable|array $handler): callable
      *
      * @see https://www.php-fig.org/psr/psr-15/#22-psrhttpservermiddlewareinterface PSR-15 Middleware
     */
    public function __construct(
        private readonly Router $router,
        private readonly mixed $handlerResolver,
    ) {
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $uri = $request->getUri()->getPath() ?: '/';

        try {
            $result = $this->router->match($method, $uri);
        } catch (RouteNotFoundException) {
            return $this->errorResponse(404, 'Not Found');
        } catch (MethodNotAllowedException $e) {
            $response = $this->errorResponse(405, 'Method Not Allowed');

            return $response->withHeader('Allow', implode(', ', $e->allowedMethods));
        }

        // Inject route parameters as request attributes
        foreach ($result->parameters->all() as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        $request = $request->withAttribute('_route', $result->routeName());

        // Resolve the handler
        $handler = ($this->handlerResolver)($result->handler());

        $finalHandler = new class ($handler) implements RequestHandlerInterface {
            public function __construct(
                private readonly mixed $handler,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $handlerResult = ($this->handler)($request);

                if ($handlerResult instanceof ResponseInterface) {
                    return $handlerResult;
                }

                if ($handlerResult === null) {
                    return new Response(204);
                }

                if (is_string($handlerResult)) {
                    return (new Response(200))
                        ->withHeader('Content-Type', 'text/html; charset=utf-8')
                        ->withBody(Stream::fromString($handlerResult));
                }

                if (is_array($handlerResult) || is_object($handlerResult)) {
                    $json = json_encode($handlerResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                    return (new Response(200))
                        ->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withBody(Stream::fromString($json));
                }

                return (new Response(200))->withBody(Stream::fromString((string) $handlerResult));
            }
        };

        // Collect middleware: global + route-specific
        $routeMiddleware = $result->middleware;
        $allMiddleware = array_merge($this->globalMiddleware, $this->resolveMiddleware($routeMiddleware));

        if ($allMiddleware === []) {
            return $finalHandler->handle($request);
        }

        $dispatcher = new MiddlewareDispatcher($finalHandler);
        $dispatcher->pipeAll($allMiddleware);

        return $dispatcher->handle($request);
    }

    /**
     * @param list<string|MiddlewareInterface> $definitions
     * @return list<MiddlewareInterface>
      *
      * @see https://www.php-fig.org/psr/psr-15/#22-psrhttpservermiddlewareinterface PSR-15 Middleware
     */
    private function resolveMiddleware(array $definitions): array
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            if ($definition instanceof MiddlewareInterface) {
                $resolved[] = $definition;
            } elseif (is_string($definition)) {
                $instance = ($this->handlerResolver)($definition);
                if ($instance instanceof MiddlewareInterface) {
                    $resolved[] = $instance;
                }
            }
        }

        return $resolved;
    }

    private function errorResponse(int $code, string $message): ResponseInterface
    {
        $body = json_encode(['error' => $message, 'status' => $code], JSON_THROW_ON_ERROR);

        return (new Response($code))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(Stream::fromString($body));
    }
}

