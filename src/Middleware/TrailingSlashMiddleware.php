<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Removes trailing slashes from the path, so "/users/" and "/users" reach
 * one route. A GET or HEAD is redirected (301) to the path without them,
 * keeping the query; other methods, whose body a redirect would drop,
 * continue with the path rewritten. The Location is a path, not a URI: it
 * names no host, so a forged Host header cannot reach it, and leading
 * slashes collapse to one, so "//evil.example/" cannot become the
 * protocol-relative "//evil.example".
 *
 * ARFA 1.3: P2 (a stage in front of routing). Spec V4.3.3: final,
 * stateless.
 *
 * Preconditions: placed before the router in the middleware queue.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none; the request is copied.
 *
 * Example:
 *
 *     $app->pipe(new TrailingSlashMiddleware());          // 301 for GET /users/
 *     $app->pipe(new TrailingSlashMiddleware(redirect: false)); // rewrite only
 *
 * References: RFC 9110 §15.4.2 (301) and §10.2.2 (Location may be a
 * relative reference); RFC 3986 §4.2 and §6.2.4; OWASP Unvalidated
 * Redirects and Forwards Cheat Sheet; ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-15.4.2
 */
final class TrailingSlashMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $redirect = true,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path === '/' || !str_ends_with($path, '/')) {
            return $handler->handle($request);
        }

        $trimmed = '/' . ltrim(rtrim($path, '/'), '/');

        if ($this->redirect && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $query = $uri->getQuery();

            return new Response(301, headers: ['Location' => $query === '' ? $trimmed : $trimmed . '?' . $query]);
        }

        return $handler->handle($request->withUri($uri->withPath($trimmed), true));
    }
}
