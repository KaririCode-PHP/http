<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

/**
 * Immutable CORS policy configuration.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): all with* methods
 * return new instances. Static factories provide common presets.
 *
 * Spec V4.0: final readonly class.
 *
 * Each property maps directly to a CORS response header:
 *   allowedOrigins   → Access-Control-Allow-Origin
 *   allowedMethods   → Access-Control-Allow-Methods
 *   allowedHeaders   → Access-Control-Allow-Headers
 *   exposedHeaders   → Access-Control-Expose-Headers
 *   maxAge           → Access-Control-Max-Age
 *   allowCredentials → Access-Control-Allow-Credentials
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 * @see https://fetch.spec.whatwg.org/#http-cors-protocol
 */
final readonly class CorsConfiguration
{
    /**
     * @param list<string> $allowedOrigins  Exact origins or ['*'] for wildcard
     * @param list<string> $allowedMethods  HTTP methods (uppercase)
     * @param list<string> $allowedHeaders  Request headers the client may send
     * @param list<string> $exposedHeaders  Response headers the client may read
     * @param int          $maxAge          Preflight cache duration in seconds
     * @param bool         $allowCredentials Whether to allow cookies/auth headers
     */
    public function __construct(
        public array $allowedOrigins = ['*'],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        public array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        public array $exposedHeaders = [],
        public int $maxAge = 86400,
        public bool $allowCredentials = false,
    ) {
    }

    // ── Static Factories ─────────────────────────────────────────────────

    /**
     * Permissive policy — allows all origins, methods, and headers.
     * Suitable for public APIs during development.
     */
    public static function permissive(): self
    {
        return new self(
            allowedOrigins: ['*'],
            allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            allowedHeaders: ['*'],
            exposedHeaders: [],
            maxAge: 86400,
            allowCredentials: false,
        );
    }

    /**
     * Strict single-origin policy with credentials support.
     * Suitable for SPAs with a known frontend origin.
     */
    public static function singleOrigin(string $origin): self
    {
        return new self(
            allowedOrigins: [$origin],
            allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
            exposedHeaders: [],
            maxAge: 86400,
            allowCredentials: true,
        );
    }

    // ── Wither Methods ───────────────────────────────────────────────────

    /** @param list<string> $origins */
    public function withAllowedOrigins(array $origins): self
    {
        return new self($origins, $this->allowedMethods, $this->allowedHeaders, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /** @param list<string> $methods */
    public function withAllowedMethods(array $methods): self
    {
        return new self($this->allowedOrigins, $methods, $this->allowedHeaders, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /** @param list<string> $headers */
    public function withAllowedHeaders(array $headers): self
    {
        return new self($this->allowedOrigins, $this->allowedMethods, $headers, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /** @param list<string> $headers */
    public function withExposedHeaders(array $headers): self
    {
        return new self($this->allowedOrigins, $this->allowedMethods, $this->allowedHeaders, $headers, $this->maxAge, $this->allowCredentials);
    }

    public function withMaxAge(int $seconds): self
    {
        return new self($this->allowedOrigins, $this->allowedMethods, $this->allowedHeaders, $this->exposedHeaders, $seconds, $this->allowCredentials);
    }

    public function withCredentials(bool $allow = true): self
    {
        return new self($this->allowedOrigins, $this->allowedMethods, $this->allowedHeaders, $this->exposedHeaders, $this->maxAge, $allow);
    }

    // ── Query Methods ────────────────────────────────────────────────────

    public function isWildcardOrigin(): bool
    {
        return $this->allowedOrigins === ['*'];
    }

    public function isWildcardHeaders(): bool
    {
        return $this->allowedHeaders === ['*'];
    }

    public function isOriginAllowed(string $origin): bool
    {
        if ($this->isWildcardOrigin()) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), $this->allowedMethods, true);
    }
}
