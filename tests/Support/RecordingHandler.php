<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

use KaririCode\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request handler that keeps the requests it receives and answers each
 * with the same response (200 unless told otherwise).
 */
final class RecordingHandler implements RequestHandlerInterface
{
    /** @var list<ServerRequestInterface> */
    public private(set) array $requests = [];

    public function __construct(
        private readonly ResponseInterface $response = new Response(200),
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }

    public function last(): ServerRequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('The handler was not called.')];
    }
}
