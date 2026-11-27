<?php

declare(strict_types=1);

namespace KaririCode\Http\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One position in a middleware queue, as the handler a middleware passes
 * the request to. handle() runs the middleware at $position with the next
 * step as its handler, or the fallback once the queue is done. Steps are
 * immutable, so a middleware may call its handler more than once (a retry)
 * and each call starts from the same place.
 *
 * ARFA 1.3: P1 (immutable step) and P2 (the chain is built per call).
 * Spec V4.3.3: final readonly; created by MiddlewareDispatcher.
 *
 * Preconditions: $position is between 0 and the queue length.
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: those of the middleware and the fallback.
 *
 * Example:
 *
 *     $response = (new MiddlewareStep([$cors, $auth], $router))->handle($request);
 *
 * References: PSR-15 RequestHandlerInterface; ADR-002.
 *
 * @see https://www.php-fig.org/psr/psr-15/#21-psrhttpserverrequesthandlerinterface
 */
final readonly class MiddlewareStep implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $queue
     */
    public function __construct(
        private array $queue,
        private RequestHandlerInterface $fallback,
        private int $position = 0,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->queue[$this->position] ?? null;

        return $middleware === null
            ? $this->fallback->handle($request)
            : $middleware->process($request, new self($this->queue, $this->fallback, $this->position + 1));
    }
}
