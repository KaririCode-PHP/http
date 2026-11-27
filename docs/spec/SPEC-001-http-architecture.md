# SPEC-001: HTTP Architecture Specification

## Version

4.0.0 — 2026-03-03

## Abstract

KaririCode\Http is a PSR-7/15/17 compliant HTTP message library for PHP 8.4+ with CORS middleware, mTLS support, SAPI emitter, and Router V4.1 integration via `HttpNormalizer`.

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                     PSR-7 Messages                                   │
│  Request │ Response │ ServerRequest │ Stream │ Uri │ UploadedFile    │
│  All implement PSR-7 interfaces, immutable with*() methods          │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                     PSR-17 Factories                                 │
│  HttpFactory (single class implementing all 6 factory interfaces)   │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                     Middleware Stack                                  │
│  CorsMiddleware │ ClientCertificateMiddleware │ TrailingSlashMiddle  │
│  MiddlewareDispatcher (onion-model PSR-15 dispatcher)               │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Emitter: SapiEmitter      Security: TlsConfiguration, ClientCert   │
│  Integration: HttpNormalizer, RouterRequestHandler                   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. PSR Compliance Matrix

| PSR | Interface | Implementation | Status |
|---|---|---|---|
| PSR-7 | `MessageInterface` | `HasHeaders` trait | ✅ |
| PSR-7 | `RequestInterface` | `Request` (final) | ✅ |
| PSR-7 | `ResponseInterface` | `Response` (final) | ✅ |
| PSR-7 | `ServerRequestInterface` | `ServerRequest` (final) | ✅ |
| PSR-7 | `StreamInterface` | `Stream` (final) | ✅ |
| PSR-7 | `UriInterface` | `Uri` (final readonly) | ✅ |
| PSR-7 | `UploadedFileInterface` | `UploadedFile` (final) | ✅ |
| PSR-15 | `MiddlewareInterface` | Protocol-agnostic contract | ✅ |
| PSR-15 | `RequestHandlerInterface` | `RouterRequestHandler` | ✅ |
| PSR-17 | All 6 factories | `HttpFactory` | ✅ |

---

## 3. Message Model

### Header Normalization

The `HasHeaders` trait stores headers in dual representation: `array<lowercase-key, list<string>>` for lookups and `array<lowercase-key, original-case>` for output. This satisfies PSR-7's case-insensitive requirement while preserving original casing.

### Uri (RFC 3986)

`Uri` is `final readonly` with component-level parsing and recomposition. Supports scheme, authority, path, query, and fragment. Port is only included in `getAuthority()` when non-standard for the scheme.

### Stream

Wraps a PHP resource handle with lazy initialization. Supports readable, writable, and seekable modes. `__toString()` rewinds and reads the full contents.

---

## 4. Middleware

### CorsMiddleware

Configuration via `CorsConfiguration` value object:

| Property | Type | Default |
|---|---|---|
| `allowedOrigins` | `list<string>` | `['*']` |
| `allowedMethods` | `list<string>` | `['GET', 'POST', ...]` |
| `allowedHeaders` | `list<string>` | `['*']` |
| `exposedHeaders` | `list<string>` | `[]` |
| `maxAge` | `int` | `86400` |
| `allowCredentials` | `bool` | `false` |

### ClientCertificateMiddleware

Extracts `ClientCertificate` from TLS context or proxy headers. The certificate VO provides: `subjectDn`, `issuerDn`, `serialNumber`, `notBefore`, `notAfter`, `sha256Fingerprint`, `commonName()`, `organization()`, `isExpired()`, `isWithinValidityPeriod()`.

### TrailingSlashMiddleware

Strips or redirects trailing slashes before routing to prevent 404s from path normalization mismatches.

---

## 5. Security

### TlsConfiguration

| Factory | Mode |
|---|---|
| `serverOnly(cert, key)` | Server-side TLS only |
| `mutual(cert, key, ca)` | Full mTLS with required client cert |
| `optionalClient(cert, key, ca)` | mTLS with optional client cert |

Supports DN whitelist, SHA-256 fingerprint pinning, custom cipher lists, verify depth configuration. Produces PHP stream context options via `toStreamContextOptions()`.

---

## 6. Router Integration

### HttpNormalizer

Extracts `(method, uri)` from `ServerRequestInterface` for Router V4.1 matching. Dependency: HTTP → Router.

### RouterRequestHandler

Bridges `MatchResult` → PSR-15 middleware dispatch → `ResponseInterface`. Resolves middleware class-strings via PSR-11 container. Supports both callable and class-string handlers.

---

## 7. Emitter

`SapiEmitter` sends `ResponseInterface` to the SAPI output layer: status line, headers, and body stream. Validates that headers have not been sent before emitting.

---

## 8. Error Model

| Exception | Factories |
|---|---|
| `HttpException` | `badRequest()`, `notFound()`, `methodNotAllowed()`, `internalServerError()` |
| `InvalidHeaderException` | `invalidName()`, `invalidValue()`, `emptyName()` |

---

## 9. ARFA 1.3 Compliance

| Principle | Implementation |
|---|---|
| P1 (Immutable State) | All messages use `with*()` methods returning new instances |
| P2 (Reactive Flow) | Middleware dispatcher composes handlers in onion model |
| P4 (Protocol Agnosticism) | HttpNormalizer bridges to protocol-agnostic Router |
