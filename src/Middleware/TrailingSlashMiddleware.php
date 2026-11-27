<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Normalizes trailing slashes in request URIs.
 *
 * GET requests receive a 301 redirect (RFC 9110 §15.4.2) with a
 * Location header (RFC 9110 §10.2.2) pointing to the slash-stripped
 * path. Non-GET methods are silently rewritten to avoid breaking
 * POST/PUT/DELETE semantics.
 *
 * Resolves Router ARCH-004 gap (trailing-slash ambiguity).
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): middleware pipeline stage.
 *
 * Spec V4.0: final class, no inheritance.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-6.2.3 URI path normalization
 */
final class TrailingSlashMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $redirect = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        // Only normalize if path has trailing slash and is not root
        if ($path !== '/' && str_ends_with($path, '/')) {
            $normalized = rtrim($path, '/');

            if ($this->redirect && $request->getMethod() === 'GET') {
                $newUri = $uri->withPath($normalized);
                $response = new Response(301);

                return $response->withHeader('Location', (string) $newUri);
            }

            // For non-GET: silently strip and continue
            $request = $request->withUri($uri->withPath($normalized));
        }

        return $handler->handle($request);
    }
}

