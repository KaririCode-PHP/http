<?php

declare(strict_types=1);

namespace KaririCode\Http\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 request handler that runs a queue of middleware in front of a
 * fallback handler. The first middleware piped is the outermost: it sees
 * the request first and the response last. Each middleware either returns
 * a response itself or passes the request to the next one through the
 * handler it receives.
 *
 * ARFA 1.3: P2 (reactive flow: the request through the queue, the response
 * back out). Spec V4.3.3: final; the queue grows only through pipe().
 *
 * Preconditions: middleware is piped before handle() runs.
 *
 * Thread-safety: handle() keeps no state between calls; pipe() changes the
 * queue, so build it once, at bootstrap.
 *
 * Side effects: those of the middleware and the fallback.
 *
 * Example:
 *
 *     $app = (new MiddlewareDispatcher($router))
 *         ->pipe(new CorsMiddleware($cors))      // outermost
 *         ->pipe(new TrailingSlashMiddleware());
 *     $response = $app->handle($request);
 *
 * References: PSR-15 HTTP Server Request Handlers; ADR-002.
 *
 * @see https://www.php-fig.org/psr/psr-15/
 */
final class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $queue = [];

    public function __construct(
        private readonly RequestHandlerInterface $fallback,
    ) {}

    public function pipe(MiddlewareInterface ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function pipeAll(array $middleware): self
    {
        return $this->pipe(...$middleware);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new MiddlewareStep($this->queue, $this->fallback))->handle($request);
    }
}
