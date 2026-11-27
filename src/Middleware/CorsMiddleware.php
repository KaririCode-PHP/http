<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers CORS preflights and adds the CORS headers to responses for
 * allowed origins, following a CorsConfiguration. A preflight (OPTIONS
 * with Access-Control-Request-Method) from an allowed origin gets 204 with
 * the allowed methods, headers and max-age; other requests reach the
 * handler and the response gains Access-Control-Allow-Origin (the origin
 * itself, or "*" for a public policy without credentials). With a list of
 * origins every response carries Vary: Origin, also those for origins
 * outside the list, so a cache never serves one origin's answer to another.
 * With a wildcard header policy the announced request headers are echoed
 * only when they form a valid list of field names.
 *
 * ARFA 1.3: P2 (a stage in front of the application). Spec V4.3.3: final,
 * stateless apart from its policy.
 *
 * Preconditions: placed in front of routing (MiddlewareDispatcher), so
 * preflights for paths without an OPTIONS route are answered.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $app = (new MiddlewareDispatcher($router))->pipe(new CorsMiddleware(CorsConfiguration::singleOrigin('https://app.example.com')));
 *
 * References: Fetch Standard §3.2 (CORS protocol) and §3.2.3 (Vary);
 * RFC 9110 §12.5.5 (Vary); ADR-006.
 *
 * @see https://fetch.spec.whatwg.org/#cors-preflight-fetch
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** A comma-separated list of field names (RFC 9110 §5.6.1). */
    private const string FIELD_NAME_LIST = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+(?:[ \t]*,[ \t]*[!#$%&\'*+\-.^_`|~0-9A-Za-z]+)*\z/';

    public function __construct(
        private readonly CorsConfiguration $config = new CorsConfiguration(),
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || !$this->config->isOriginAllowed($origin)) {
            return $this->vary($handler->handle($request));
        }

        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->preflight($request, $origin);
        }

        return $this->allow($handler->handle($request), $origin);
    }

    private function preflight(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $response = $this->vary(new Response(204));

        if (!$this->config->isMethodAllowed($request->getHeaderLine('Access-Control-Request-Method'))) {
            return $response;
        }

        $response = $this->allow($response, $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config->allowedMethods));
        $headers = $this->config->isWildcardHeaders()
            ? $request->getHeaderLine('Access-Control-Request-Headers')
            : implode(', ', $this->config->allowedHeaders);

        if ($headers !== '' && preg_match(self::FIELD_NAME_LIST, $headers) === 1) {
            $response = $response->withHeader('Access-Control-Allow-Headers', $headers);
        }

        return $this->config->maxAge > 0 ? $response->withHeader('Access-Control-Max-Age', (string) $this->config->maxAge) : $response;
    }

    private function allow(ResponseInterface $response, string $origin): ResponseInterface
    {
        $wildcard = $this->config->isWildcardOrigin();
        $response = $this->vary($response)->withHeader('Access-Control-Allow-Origin', $wildcard ? '*' : $origin);

        if ($this->config->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $this->config->exposedHeaders === []
            ? $response
            : $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config->exposedHeaders));
    }

    /** Adds Vary: Origin once, unless the policy answers every origin alike. */
    private function vary(ResponseInterface $response): ResponseInterface
    {
        if ($this->config->isWildcardOrigin()) {
            return $response;
        }

        foreach ($response->getHeader('Vary') as $value) {
            if (in_array('origin', array_map(static fn(string $item): string => strtolower(trim($item)), explode(',', $value)), true)) {
                return $response;
            }
        }

        return $response->withAddedHeader('Vary', 'Origin');
    }
}
