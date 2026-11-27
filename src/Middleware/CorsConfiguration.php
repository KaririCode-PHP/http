<?php

declare(strict_types=1);

namespace KaririCode\Http\Middleware;

use KaririCode\Http\Exception\InvalidConfigurationException;

/**
 * The CORS policy CorsMiddleware applies: allowed origins, methods and
 * request headers, exposed response headers, preflight max-age and whether
 * credentialed requests are allowed. The constructor refuses a policy that
 * would expose credentialed responses to every site — "*" together with
 * credentials, which the middleware would otherwise have to answer by
 * echoing whatever Origin arrived — and origins that are not "*" or
 * scheme://host[:port]; "null" is refused too, since sandboxed documents
 * and redirects send it.
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new policy). Spec V4.3.3:
 * final readonly Value Object, validated on construction.
 *
 * Preconditions: "*" stands alone and never with $allowCredentials;
 * $maxAge ≥ 0 (InvalidConfigurationException 1760 to 1762).
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $cors = CorsConfiguration::singleOrigin('https://app.example.com')
 *         ->withExposedHeaders(['X-Request-Id']);
 *
 * References: Fetch Standard §3.2 (CORS protocol), §3.2.5 (credentials);
 * RFC 6454 §6.2 (origin serialisation); OWASP HTML5 Security Cheat Sheet;
 * ADR-006.
 *
 * @see https://fetch.spec.whatwg.org/#http-cors-protocol
 */
final readonly class CorsConfiguration
{
    /** scheme "://" host [":" port], as a browser serialises an origin. */
    private const string ORIGIN = '/\A[a-z][a-z0-9+\-.]*:\/\/(?:\[[0-9a-f:.]+\]|[a-z0-9\-._~%]+)(?::\d{1,5})?\z/i';

    /**
     * @param list<string> $allowedOrigins "*" or scheme://host[:port] entries
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders request headers a preflight may announce; ["*"] echoes the announced ones
     * @param list<string> $exposedHeaders response headers scripts may read
     */
    public function __construct(
        public array $allowedOrigins = ['*'],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        public array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        public array $exposedHeaders = [],
        public int $maxAge = 86400,
        public bool $allowCredentials = false,
    ) {
        foreach ($allowedOrigins as $origin) {
            if (($origin !== '*' || count($allowedOrigins) !== 1) && preg_match(self::ORIGIN, $origin) !== 1) {
                throw InvalidConfigurationException::corsOrigin($origin);
            }
        }

        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw InvalidConfigurationException::corsWildcardWithCredentials();
        }

        if ($maxAge < 0) {
            throw InvalidConfigurationException::negativeMaxAge();
        }
    }

    /** Any origin, no credentials, any request header: for public APIs. */
    public static function permissive(): self
    {
        return new self(
            allowedOrigins: ['*'],
            allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            allowedHeaders: ['*'],
        );
    }

    /** One origin, with credentials: a browser app on its own domain. */
    public static function singleOrigin(string $origin): self
    {
        return new self(allowedOrigins: [$origin], allowCredentials: true);
    }

    /**
     * @param list<string> $origins
     */
    public function withAllowedOrigins(array $origins): self
    {
        return new self($origins, $this->allowedMethods, $this->allowedHeaders, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /**
     * @param list<string> $methods
     */
    public function withAllowedMethods(array $methods): self
    {
        return new self($this->allowedOrigins, $methods, $this->allowedHeaders, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /**
     * @param list<string> $headers
     */
    public function withAllowedHeaders(array $headers): self
    {
        return new self($this->allowedOrigins, $this->allowedMethods, $headers, $this->exposedHeaders, $this->maxAge, $this->allowCredentials);
    }

    /**
     * @param list<string> $headers
     */
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

    public function isWildcardOrigin(): bool
    {
        return $this->allowedOrigins === ['*'];
    }

    public function isWildcardHeaders(): bool
    {
        return $this->allowedHeaders === ['*'];
    }

    /** Origins compare case-insensitively, as browsers serialise them in lower case. */
    public function isOriginAllowed(string $origin): bool
    {
        if ($this->isWildcardOrigin()) {
            return true;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if (strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), array_map(strtoupper(...), $this->allowedMethods), true);
    }
}
