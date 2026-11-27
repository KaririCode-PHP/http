# KaririCode HTTP

PSR-7 messages, PSR-17 factories and PSR-15 dispatch for PHP 8.4, with CORS
and client-certificate middleware, a SAPI emitter and the bridge to
[kariricode/router](https://github.com/KaririCode-PHP/router). Every message
validates what it holds, so a header value, method or reason phrase cannot
split the response on the wire.

[![CI](https://github.com/KaririCode-PHP/http/actions/workflows/ci.yml/badge.svg)](https://github.com/KaririCode-PHP/http/actions/workflows/ci.yml)

## Installation

```bash
composer require kariricode/http
```

Requires PHP 8.4. ext-openssl is suggested: with it, certificate fingerprints
are computed from the PEM instead of taken from a header.

## Messages

```php
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;

$request = ServerRequest::fromGlobals();
$page = (int) ($request->getQueryParams()['page'] ?? 1);

$response = (new Response(201))
    ->withHeader('Location', '/orders/42')
    ->withBody(Stream::fromString('{"id":42}'));
```

Values are checked when they enter a message:

```php
$response->withHeader('Location', "/x\r\nSet-Cookie: admin=1");
// InvalidMessageException [HTTP_1701]: Header "Location" needs a string value
// without CR, LF or other control characters.
```

`ServerRequest::fromGlobals()` treats the client's input as untrusted: a Host
header that is not `host[:port]` does not reach the URI, the scheme comes from
the server (`HTTPS`), never from `X-Forwarded-Proto`, and malformed header
fields are skipped instead of failing the request.

## Dispatch and routing

```php
use KaririCode\Http\Emitter\SapiEmitter;
use KaririCode\Http\Handler\MiddlewareDispatcher;
use KaririCode\Http\Integration\RouterRequestHandler;
use KaririCode\Http\Middleware\CorsConfiguration;
use KaririCode\Http\Middleware\CorsMiddleware;
use KaririCode\Http\Middleware\TrailingSlashMiddleware;
use KaririCode\Router\Core\RouteRegistry;

$routes = (new RouteRegistry())
    ->get('/users/{id}', ShowUser::class, name: 'user.show', middleware: ['auth']);

$router = new RouterRequestHandler($routes, static fn(mixed $target): mixed => $container->get($target));

$app = (new MiddlewareDispatcher($router))
    ->pipe(new CorsMiddleware(CorsConfiguration::singleOrigin('https://app.example.com')))
    ->pipe(new TrailingSlashMiddleware());

(new SapiEmitter())->emit($app->handle(ServerRequest::fromGlobals()));
```

- Route parameters and `_route` become request attributes.
- A route middleware name that does not resolve to a PSR-15 middleware throws
  (`HTTP_1766`) instead of being skipped, so `auth` cannot silently drop out.
- A handler may return a response, `null` (204), a string (HTML), an array or
  object (JSON) or another scalar (text).
- `TrailingSlashMiddleware` redirects `GET /users/` to `/users` with a
  host-free Location.

## CORS

```php
CorsConfiguration::permissive();                         // public API: "*", no credentials
CorsConfiguration::singleOrigin('https://app.example.com'); // one origin, with credentials
new CorsConfiguration(allowedOrigins: ['https://a.example', 'https://b.example'], allowCredentials: true);
```

Credentials with the wildcard origin are refused at construction
(`HTTP_1760`): echoing the request's Origin would let any site read
credentialed responses. Listed policies add `Vary: Origin` to every response.

## Client certificates (mTLS)

```php
use KaririCode\Http\Middleware\ClientCertificateMiddleware;
use KaririCode\Http\Security\TlsConfiguration;

$tls = TlsConfiguration::mutual('/etc/tls/server.pem', '/etc/tls/server.key', '/etc/tls/clients-ca.pem')
    ->withTrustedProxies(['10.0.0.0/8'])          // only these peers may send X-SSL-Client-* headers
    ->withAllowedDns(['CN=billing,O=Acme'])
    ->withTrustedFingerprints(['3f:2a:…']);

$app->pipe(new ClientCertificateMiddleware($tls));
// handlers read $request->getAttribute('client_certificate')
```

The certificate is believed only from the web server's `SSL_CLIENT_*`
variables, or from `X-SSL-Client-*` headers when `REMOTE_ADDR` is a trusted
proxy; from anyone else those headers are removed. Verification must read
`SUCCESS`, and with pinning a certificate without a fingerprint is refused.

## Errors

Each failure is a final exception in a kariricode/contract family, with a code
from block 1700–1799 (prefix `HTTP`):

| Exception | Family | Codes |
|---|---|---|
| `InvalidMessageException` | Validation (`\InvalidArgumentException`, as PSR-7 asks) | 1700–1712 |
| `InvalidUriException` | Validation (`\InvalidArgumentException`) | 1720–1723 |
| `StreamException` | Runtime | 1730–1735 |
| `UploadedFileException` | Runtime | 1740–1742 |
| `EmitterException` | Runtime | 1750 |
| `InvalidConfigurationException` | Configuration | 1760–1766 |

Messages name the rule that failed and never repeat a value the client sent.

## Development

```bash
composer install
kcode init          # KaririCode Devkit 1.2.1: installs the pinned toolchain into .kcode/
composer check      # style, PHPStan (max), Psalm (1), complexity, tests, mutation
composer security   # composer audit (runtime and toolchain), secret scan
```

## Documentation

| Document | Topic |
|---|---|
| [SPEC-001](docs/spec/SPEC-001-http-architecture.md) | architecture and behaviour |
| [ADR-001](docs/adr/ADR-001-psr-messages.md) | PSR-7/15/17, immutability |
| [ADR-002](docs/adr/ADR-002-router-integration.md) | router bridge, fail-closed middleware |
| [ADR-003](docs/adr/ADR-003-exception-families-and-code-block.md) | exception families, codes 1700–1799 |
| [ADR-004](docs/adr/ADR-004-toolchain-through-kcode.md) | quality gates |
| [ADR-005](docs/adr/ADR-005-message-validation.md) | message validation |
| [ADR-006](docs/adr/ADR-006-cors-policy.md) | CORS policy |
| [ADR-007](docs/adr/ADR-007-client-certificate-trust.md) | client-certificate trust |
| [Quality report](docs/quality/QD-V433-COMPLIANCE.md) | Spec V4.3.3 gates and deviations |
| [Security policy](SECURITY.md) | reporting, threat model |

## License

MIT. See [LICENSE](LICENSE).
