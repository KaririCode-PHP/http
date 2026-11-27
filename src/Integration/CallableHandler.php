<?php

declare(strict_types=1);

namespace KaririCode\Http\Integration;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A route handler as a PSR-15 request handler: it calls the handler with
 * the request and turns what it returns into a response. A response passes
 * through; null is 204; a string is an HTML page (the handler escapes what
 * it interpolates); an array or object is JSON; any other scalar is text.
 * A value that is not callable is answered with 500, since a route pointing
 * at nothing is a wiring error the client cannot fix.
 *
 * ARFA 1.3: P4 (handlers need not know PSR-15). Spec V4.3.3: final
 * readonly adapter.
 *
 * Preconditions: $handler is callable with one ServerRequestInterface.
 *
 * Thread-safety: as safe as the handler it wraps.
 *
 * Side effects: those of the handler.
 *
 * Example:
 *
 *     $response = (new CallableHandler(static fn(ServerRequestInterface $r): array => ['id' => $r->getAttribute('id')]))->handle($request);
 *     // 200, application/json
 *
 * References: PSR-15 RequestHandlerInterface; RFC 9110 §15.3.5 (204);
 * OWASP XSS Prevention Cheat Sheet; ADR-002.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 */
final readonly class CallableHandler implements RequestHandlerInterface
{
    public function __construct(
        private mixed $handler,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!is_callable($this->handler)) {
            return new Response(500, headers: ['Content-Type' => 'application/json; charset=utf-8'], body: Stream::fromString('{"error":"Internal Server Error","status":500}'));
        }

        return self::response(($this->handler)($request));
    }

    private static function response(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null => new Response(204),
            is_string($result) => new Response(200, headers: ['Content-Type' => 'text/html; charset=utf-8'], body: Stream::fromString($result)),
            is_array($result), is_object($result) => new Response(200, headers: ['Content-Type' => 'application/json; charset=utf-8'], body: Stream::fromString(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))),
            default => new Response(200, headers: ['Content-Type' => 'text/plain; charset=utf-8'], body: Stream::fromString(is_scalar($result) ? (string) $result : '')),
        };
    }
}
