<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-Origin Resource Sharing middleware (Fetch Living Standard §3.2).
 *
 * Handles two distinct flows:
 *   1. **Preflight** — OPTIONS with Origin + Access-Control-Request-Method
 *      → short-circuits with 204 No Content and CORS headers.
 *   2. **Actual request** — any other method with Origin header
 *      → delegates to next handler, then decorates response with CORS headers.
 *
 * When credentials are enabled, the wildcard origin ('*') is replaced
 * with the actual request origin per the spec requirement that
 * Access-Control-Allow-Origin must echo the specific origin when
 * Access-Control-Allow-Credentials is 'true'.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): middleware pipeline stage
 * that decorates responses without altering application logic.
 *
 * Spec V4.0: final class, no inheritance.
 *
 * @see https://fetch.spec.whatwg.org/#http-cors-protocol
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CorsConfiguration $config = new CorsConfiguration(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // No Origin header → not a CORS request, pass through
        if ($origin === '') {
            return $handler->handle($request);
        }

        // Origin not allowed → pass through without CORS headers
        if (!$this->config->isOriginAllowed($origin)) {
            return $handler->handle($request);
        }

        // Preflight request → short-circuit with 204
        if ($this->isPreflight($request)) {
            return $this->handlePreflight($request, $origin);
        }

        // Actual request → delegate then decorate response
        $response = $handler->handle($request);

        return $this->addCorsHeaders($response, $origin);
    }

    // ── Preflight ────────────────────────────────────────────────────────

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function handlePreflight(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $requestedMethod = $request->getHeaderLine('Access-Control-Request-Method');

        // Requested method not allowed → 204 without CORS headers
        if (!$this->config->isMethodAllowed($requestedMethod)) {
            return new Response(204);
        }

        $response = new Response(204);
        $response = $this->addCorsHeaders($response, $origin);

        // Preflight-specific: allowed methods + headers + max age
        $response = $response->withHeader(
            'Access-Control-Allow-Methods',
            implode(', ', $this->config->allowedMethods),
        );

        $allowedHeaders = $this->config->isWildcardHeaders()
            ? $request->getHeaderLine('Access-Control-Request-Headers')
            : implode(', ', $this->config->allowedHeaders);

        if ($allowedHeaders !== '') {
            $response = $response->withHeader(
                'Access-Control-Allow-Headers',
                $allowedHeaders,
            );
        }

        if ($this->config->maxAge > 0) {
            $response = $response->withHeader(
                'Access-Control-Max-Age',
                (string) $this->config->maxAge,
            );
        }

        return $response;
    }

    // ── Shared Header Decoration ─────────────────────────────────────────

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        // When credentials are enabled, must echo specific origin (not '*')
        $allowOrigin = ($this->config->allowCredentials || !$this->config->isWildcardOrigin())
            ? $origin
            : '*';

        $response = $response->withHeader('Access-Control-Allow-Origin', $allowOrigin);

        if ($this->config->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->config->exposedHeaders !== []) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config->exposedHeaders),
            );
        }

        // Vary: Origin — caches must differentiate by origin
        if (!$this->config->isWildcardOrigin() || $this->config->allowCredentials) {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        return $response;
    }
}
