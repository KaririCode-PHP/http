<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Handler;

use KaririCode\Http\Handler\MiddlewareDispatcher;
use KaririCode\Http\Handler\MiddlewareStep;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(MiddlewareDispatcher::class)]
#[CoversClass(MiddlewareStep::class)]
final class MiddlewareDispatcherTest extends TestCase
{
    public function testFallbackHandlerCalledWhenNoMiddleware(): void
    {
        $fallback = $this->createHandler(new Response(200));
        $dispatcher = new MiddlewareDispatcher($fallback);

        $response = $dispatcher->handle(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSingleMiddlewareModifiesResponse(): void
    {
        $fallback = $this->createHandler(new Response(200));
        $middleware = $this->createMiddleware(static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            $response = $handler->handle($request);
            return $response->withHeader('X-Added', 'yes');
        });

        $dispatcher = new MiddlewareDispatcher($fallback);
        $dispatcher->pipe($middleware);

        $response = $dispatcher->handle(new ServerRequest('GET', '/'));

        self::assertSame('yes', $response->getHeaderLine('X-Added'));
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $order = [];

        $fallback = $this->createHandler((new Response(200))->withBody(Stream::fromString('base')));

        $first = $this->createMiddleware(static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use (&$order): ResponseInterface {
            $order[] = 'first-before';
            $response = $handler->handle($request);
            $order[] = 'first-after';
            return $response->withHeader('X-First', '1');
        });

        $second = $this->createMiddleware(static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use (&$order): ResponseInterface {
            $order[] = 'second-before';
            $response = $handler->handle($request);
            $order[] = 'second-after';
            return $response->withHeader('X-Second', '2');
        });

        $dispatcher = new MiddlewareDispatcher($fallback);
        $dispatcher->pipe($first);
        $dispatcher->pipe($second);

        $response = $dispatcher->handle(new ServerRequest('GET', '/'));

        // Onion model: first wraps second wraps fallback
        self::assertSame(['first-before', 'second-before', 'second-after', 'first-after'], $order);
        self::assertSame('1', $response->getHeaderLine('X-First'));
        self::assertSame('2', $response->getHeaderLine('X-Second'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $fallback = $this->createHandler(new Response(200));
        $blocker = $this->createMiddleware(static function (): ResponseInterface {
            return new Response(403);
        });

        $dispatcher = new MiddlewareDispatcher($fallback);
        $dispatcher->pipe($blocker);

        $response = $dispatcher->handle(new ServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMiddlewareCanModifyRequest(): void
    {
        $fallback = new RecordingHandler();

        $injector = $this->createMiddleware(static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            return $handler->handle($request->withAttribute('injected', 'value'));
        });

        $dispatcher = new MiddlewareDispatcher($fallback);
        $dispatcher->pipe($injector);
        $dispatcher->handle(new ServerRequest('GET', '/'));

        self::assertSame('value', $fallback->last()->getAttribute('injected'));
    }

    public function testPipeAllAddsMultipleMiddleware(): void
    {
        $fallback = $this->createHandler(new Response(200));

        $m1 = $this->createMiddleware(static function (ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface {
            return $h->handle($r)->withHeader('X-A', '1');
        });
        $m2 = $this->createMiddleware(static function (ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface {
            return $h->handle($r)->withHeader('X-B', '2');
        });

        $dispatcher = new MiddlewareDispatcher($fallback);
        $dispatcher->pipeAll([$m1, $m2]);

        $response = $dispatcher->handle(new ServerRequest('GET', '/'));

        self::assertSame('1', $response->getHeaderLine('X-A'));
        self::assertSame('2', $response->getHeaderLine('X-B'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * @param \Closure(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $fn
     */
    private function createMiddleware(\Closure $fn): MiddlewareInterface
    {
        return new class ($fn) implements MiddlewareInterface {
            /**
             * @param \Closure(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $fn
             */
            public function __construct(private readonly \Closure $fn) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return ($this->fn)($request, $handler);
            }
        };
    }
}
