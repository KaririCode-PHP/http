<?php

declare(strict_types=1);

namespace KaririCode\Http\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware dispatcher (onion model).
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): middleware forms
 * a composable pipeline where each layer wraps the next handler.
 * The stack builds inside-out so the first piped middleware is
 * the outermost layer (first to process request, last to see response).
 *
 * The final handler produces the base response when no middleware
 * short-circuits. Anonymous handler classes are used to avoid
 * allocating named classes for a single-purpose adapter.
 * Spec V4.0: final class, no inheritance.
 *
 * @see https://www.php-fig.org/psr/psr-15/ PSR-15 HTTP Server Request Handlers
 */
final class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $stack = [];

    public function __construct(
        private readonly RequestHandlerInterface $fallback,
    ) {
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->stack[] = $middleware;

        return $this;
    }

    /**
     * @param list<MiddlewareInterface> $middlewares
      *
      * @see https://www.php-fig.org/psr/psr-15/ PSR-15 HTTP Server Request Handlers
     */
    public function pipeAll(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->stack[] = $middleware;
        }

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->fallback;

        // Build from inside-out: last piped = outermost
        foreach (array_reverse($this->stack) as $middleware) {
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}

