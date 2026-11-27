<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Exception\ConfigurationException;

/**
 * A middleware, emitter or TLS setting cannot be used safely or at all:
 * CORS that would let any origin read credentialed responses, an origin
 * that is not scheme://host[:port], a negative max-age, a trusted proxy
 * that is not an IP address or CIDR range, a buffer size or verify depth
 * below 1, a route middleware that does not resolve to a PSR-15
 * middleware. These are wiring errors, found when the object is built or
 * the route first runs; none is skipped, so a middleware that guards a
 * route cannot drop out silently.
 *
 * Preconditions: messages name the setting; origin() and trustedProxy()
 * quote the configured value, which comes from the application.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     CorsConfiguration::permissive()->withCredentials();  // throws, 1760
 *
 * References: Fetch Standard §3.2.5 (CORS protocol and credentials);
 * KaririCode Spec V4.3.3 §8.4; ADR-003, ADR-006.
 *
 * @see https://fetch.spec.whatwg.org/#cors-protocol-and-credentials
 */
final class InvalidConfigurationException extends ConfigurationException
{
    public static function corsWildcardWithCredentials(): self
    {
        return new self(HttpCode::CorsWildcardWithCredentials, 'CORS with credentials needs explicit origins; "*" would let any site read credentialed responses.');
    }

    public static function corsOrigin(string $origin): self
    {
        return new self(HttpCode::InvalidCorsOrigin, sprintf('The CORS origin %s is not "*" or scheme://host[:port].', self::quote($origin)));
    }

    public static function negativeMaxAge(): self
    {
        return new self(HttpCode::NegativeMaxAge, 'The CORS max-age must be zero or more seconds.');
    }

    public static function trustedProxy(string $proxy): self
    {
        return new self(HttpCode::InvalidTrustedProxy, sprintf('The trusted proxy %s is not an IP address or a CIDR range.', self::quote($proxy)));
    }

    public static function bufferSize(): self
    {
        return new self(HttpCode::InvalidBufferSize, 'The emitter buffer size must be at least 1 byte.');
    }

    public static function verifyDepth(): self
    {
        return new self(HttpCode::InvalidVerifyDepth, 'The certificate verify depth must be at least 1.');
    }

    public static function unresolvableMiddleware(string $definition): self
    {
        return new self(HttpCode::UnresolvableMiddleware, sprintf('The route middleware %s does not resolve to a PSR-15 middleware.', self::quote($definition)));
    }
}
