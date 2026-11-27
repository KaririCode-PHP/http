# ADR-002: Router Integration and Security Middleware

## Status

Accepted — V4.0.0

## Date

2026-03-03

## Context

The HTTP package must bridge to Router V4.1 without coupling the router to PSR-7. Additionally, production HTTP services require CORS handling and mTLS support as first-class middleware.

## Decision

### Router Integration: HttpNormalizer

`HttpNormalizer` implements the `RequestNormalizer` contract from kariricode/router. It extracts `(method, uri)` from any PSR-7 `ServerRequestInterface`:

```php
// Dependency: HTTP → Router (never reversed)
$normalizer = new HttpNormalizer();
$normalized = $normalizer->normalize($psrRequest);
// $normalized->method === 'GET'
// $normalized->uri === '/users/42'
// $normalized->original === $psrRequest
```

`RouterRequestHandler` bridges matched routes back into PSR-15 middleware dispatch, resolving middleware class-strings via a PSR-11 container.

### CORS Middleware

`CorsMiddleware` implements the W3C CORS specification with a `CorsConfiguration` value object:

- Preflight (`OPTIONS`) handling with method/header validation
- Origin allowlist with wildcard support
- Credentials, max-age, and exposed headers configuration
- Idempotent: safe to apply multiple times in nested middleware stacks

### Client Certificate Middleware

`ClientCertificateMiddleware` extracts X.509 client certificates from TLS connections or proxy headers (`X-Client-Cert`) and attaches a `ClientCertificate` value object to the request attributes.

`TlsConfiguration` encapsulates mTLS settings: CA file, allowed DNs, trusted fingerprints, peer verify mode, cipher suites.

### Trailing Slash Middleware

`TrailingSlashMiddleware` normalizes trailing slashes before routing, preventing duplicate route registration.

## Consequences

### Positive

- Router has zero PSR-7 knowledge — `HttpNormalizer` lives in kariricode/http.
- CORS is declarative via `CorsConfiguration` — no manual header manipulation.
- mTLS validation is reusable across HTTP and WebSocket (shared `ClientCertificate` VO).

### Negative

- `RouterRequestHandler` requires a PSR-11 container for middleware class-string resolution.

## References

- ARFA 1.3 — P4 (Protocol Agnosticism)
- W3C CORS: https://www.w3.org/TR/cors/
- RFC 8446 — TLS 1.3 (mutual authentication)
