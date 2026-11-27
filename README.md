# KaririCode HTTP

PSR-7/15/17 compliant HTTP message library for PHP 8.4+ with CORS middleware, mTLS support, SAPI emitter, and Router V4.1 integration.

## Overview

KaririCode HTTP provides the full HTTP message stack: immutable PSR-7 messages, PSR-15 middleware dispatcher, PSR-17 factories, security middleware (CORS, mTLS, trailing slash normalization), and a bridge to Router V4.1 via `HttpNormalizer`.

## Features

- **Full PSR-7** — Request, Response, ServerRequest, Stream, Uri, UploadedFile
- **PSR-15 middleware** — onion-model dispatcher with class-string resolution via PSR-11
- **PSR-17 factories** — single `HttpFactory` class implementing all 6 factory interfaces
- **CORS middleware** — W3C-compliant with `CorsConfiguration` value object
- **mTLS support** — `TlsConfiguration` with DN whitelist, fingerprint pinning, peer verify modes
- **Client certificate** — extraction from TLS context or proxy headers (`X-Client-Cert`)
- **SAPI emitter** — sends responses to PHP's output layer
- **Router integration** — `HttpNormalizer` and `RouterRequestHandler` bridge to Router V4.1
- **Trailing slash** — middleware for path normalization before routing
- **Zero external deps** — only `kariricode/contract` and PSR interfaces required

## Installation

```bash
composer require kariricode/http
```

## Quick Start

```php
use KaririCode\Http\Factory\HttpFactory;
use KaririCode\Http\Message\{ServerRequest, Response, Uri};
use KaririCode\Http\Emitter\SapiEmitter;

$factory = new HttpFactory();

// Create a server request
$request = $factory->createServerRequest('GET', '/users/42');

// Create a response
$response = $factory->createResponse(200)
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream('{"name":"Alice"}'));

// Emit to SAPI
(new SapiEmitter())->emit($response);
```

## Middleware

```php
use KaririCode\Http\Middleware\{CorsMiddleware, MiddlewareDispatcher};
use KaririCode\Http\Security\CorsConfiguration;

$cors = new CorsMiddleware(new CorsConfiguration(
    allowedOrigins: ['https://app.example.com'],
    allowedMethods: ['GET', 'POST', 'PUT', 'DELETE'],
    allowCredentials: true,
    maxAge: 3600,
));

$dispatcher = new MiddlewareDispatcher($finalHandler, $container);
$dispatcher->pipe($cors);
$dispatcher->pipe($authMiddleware);

$response = $dispatcher->handle($request);
```

## mTLS Configuration

```php
use KaririCode\Http\Security\TlsConfiguration;

$tls = TlsConfiguration::mutual(
    certFile: '/etc/ssl/server.crt',
    keyFile: '/etc/ssl/server.key',
    caFile: '/etc/ssl/ca.crt',
)->withAllowedDns(['CN=trusted-service'])
 ->withTrustedFingerprints(['sha256-abc123...']);

$streamContext = $tls->toStreamContextOptions();
```

## Router Integration

```php
use KaririCode\Http\Integration\{HttpNormalizer, RouterRequestHandler};

$normalizer = new HttpNormalizer();
$handler = new RouterRequestHandler($router, $normalizer, $container);

// PSR-15 compatible
$response = $handler->handle($serverRequest);
```

## Error Handling

| Exception | When |
|---|---|
| `HttpException::badRequest()` | Malformed request |
| `HttpException::notFound()` | Resource not found |
| `HttpException::methodNotAllowed()` | Wrong HTTP method |
| `InvalidHeaderException::invalidName()` | Invalid header name |
| `InvalidHeaderException::emptyName()` | Empty header name |

## Testing

```bash
./vendor/bin/phpunit
```

## Documentation

| Document | Location |
|---|---|
| ADR-001: PSR Compliance | `docs/adr/ADR-001-psr-compliance.md` |
| ADR-002: Router & Security | `docs/adr/ADR-002-router-security-middleware.md` |
| SPEC-001: Architecture | `docs/spec/SPEC-001-http-architecture.md` |

## License

MIT License. See [LICENSE](LICENSE) for details.
