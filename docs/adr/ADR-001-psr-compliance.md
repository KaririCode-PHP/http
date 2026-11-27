# ADR-001: PSR-7/15/17 Compliance with Immutability

## Status

Accepted — V4.0.0

## Date

2026-03-03

## Context

The PHP ecosystem converges on PSR-7 (HTTP messages), PSR-15 (HTTP middleware), and PSR-17 (HTTP factories) as the interoperability standard. Any HTTP library that ignores these PSRs creates integration friction with the wider ecosystem (Slim, Mezzio, etc.).

### Immutability Requirement

PSR-7 mandates immutable message objects — every `with*` method returns a new instance. This aligns with ARFA 1.3 P1 (Immutable State Transformation) but requires careful implementation to avoid:

- Accidental mutation of shared state (headers, body streams)
- Excessive object allocation on hot paths
- Inconsistent `with*` behavior across message types

## Decision

Implement all three PSRs with `final readonly` classes where possible:

| PSR | Interface | Implementation |
|---|---|---|
| PSR-7 | `RequestInterface` | `Request` (final) |
| PSR-7 | `ResponseInterface` | `Response` (final) |
| PSR-7 | `ServerRequestInterface` | `ServerRequest` (final) |
| PSR-7 | `StreamInterface` | `Stream` (final) |
| PSR-7 | `UriInterface` | `Uri` (final readonly) |
| PSR-7 | `UploadedFileInterface` | `UploadedFile` (final) |
| PSR-15 | `MiddlewareInterface` | Protocol-agnostic `Middleware` contract |
| PSR-17 | All 6 factory interfaces | `HttpFactory` (single class) |

### Header Normalization

Headers are stored in a case-insensitive map via the `HasHeaders` trait. The original case is preserved for output while lookups use lowercased keys. Values are always `list<string>` internally, matching PSR-7's multi-value header model.

### Stream Ownership

`Stream` wraps a PHP resource handle with lazy initialization. `UploadedFile` can be constructed from either a `StreamInterface` or a file path string, with `moveTo()` consuming the source (write-once semantics).

## Consequences

### Positive

- Drop-in compatible with any PSR-7/15/17 consuming library.
- `HttpFactory` consolidates all six PSR-17 interfaces into one class.
- Immutable messages are safe to pass through middleware pipelines without defensive cloning.

### Negative

- PSR-7's `with*` pattern allocates new objects per mutation — hot middleware paths may see allocation pressure.
- `ServerRequest` carries mutable parsed body/attributes state through immutable `with*` wrappers.

## References

- ARFA 1.3 — P1 (Immutable State Transformation)
- PSR-7: https://www.php-fig.org/psr/psr-7/
- PSR-15: https://www.php-fig.org/psr/psr-15/
- PSR-17: https://www.php-fig.org/psr/psr-17/
