# SPEC-001 — kariricode/http Architecture

**Version:** 4.0.0 · **Spec:** KaririCode V4.3.3 · **ARFA:** 1.3, level 1 · **ASVS:** 5.0, level 1
**Date:** 2026-11-27 (first written 2026-03-03)

## 1. Scope

kariricode/http implements PSR-7 messages, PSR-17 factories and PSR-15
dispatch for PHP 8.4, with middleware for CORS, client certificates and
trailing slashes, a SAPI emitter, and the bridge to kariricode/router. Every
message validates what it holds, so no value can split the wire format
(ADR-005). It is not an HTTP server and does not parse raw requests: PHP's
SAPI (or a runtime that builds PSR-7 requests) does.

Dependencies: `kariricode/exception` ^2.0.2 (family bases, code table),
`kariricode/router` ^4.1 (imported only by `src/Integration`), and the PSR
interfaces `psr/http-message` ^2.0, `psr/http-factory` ^1.1,
`psr/http-server-handler` ^1.0, `psr/http-server-middleware` ^1.0,
`psr/clock` ^1.0. ext-openssl is suggested for certificate fingerprints.

## 2. Types

| Type | Kind | Role |
|---|---|---|
| `Message\Request` | final, PSR-7 | client request: request line, headers, body |
| `Message\Response` | final, PSR-7 | status, reason phrase, headers, body |
| `Message\ServerRequest` | final, PSR-7 | server-side request; `fromGlobals()`, `fromServer()` (§6) |
| `Message\Stream` | final, PSR-7 | a PHP stream resource it owns (§5) |
| `Message\Uri` | final, PSR-7 | RFC 3986 components, checked and encoded (§4) |
| `Message\UploadedFile` | final, PSR-7 | an upload; `treeFromGlobals()` reads `$_FILES` (§5) |
| `Message\MessageState` | trait | protocol version, headers, body |
| `Message\RequestLine` | trait | method, request target, URI, Host |
| `Message\HttpGrammar` | final, static rules | token, field value, request target, version |
| `Message\Silently` | final, static | runs a filesystem call with warnings turned into return values |
| `Factory\HttpFactory` | final, PSR-17 | the six factories |
| `Handler\MiddlewareDispatcher` | final, PSR-15 handler | middleware queue in front of a fallback |
| `Handler\MiddlewareStep` | final readonly, PSR-15 handler | one immutable step of a queue |
| `Middleware\CorsMiddleware` | final, PSR-15 | CORS preflights and response headers (§8) |
| `Middleware\CorsConfiguration` | final readonly | CORS policy, validated (ADR-006) |
| `Middleware\ClientCertificateMiddleware` | final, PSR-15 | client-certificate policy (§9) |
| `Middleware\TrailingSlashMiddleware` | final, PSR-15 | path normalisation before routing |
| `Security\TlsConfiguration` | final readonly | TLS settings, client policy, trusted proxies |
| `Security\ClientCertificate` | final readonly | what the TLS layer verified |
| `Security\DistinguishedName` | final, static | RFC 4514 and one-line DN reader |
| `Security\NetworkRange` | final readonly | IPv4/IPv6 address or CIDR range |
| `Security\PeerVerifyMode` | enum | None, Optional, Require |
| `Security\SystemClock` | final readonly, PSR-20 | wall clock in UTC |
| `Integration\HttpNormalizer` | final | router `RequestNormalizer` for PSR-7 requests |
| `Integration\RouterRequestHandler` | final, PSR-15 handler | match, route middleware, handler (§10) |
| `Integration\CallableHandler` | final readonly, PSR-15 handler | route handler results to responses |
| `Emitter\SapiEmitter` | final | status line, headers and body through the SAPI (§11) |
| `Enum\StatusCode` | backed enum | registered statuses, phrases, classes |
| `Exception\HttpCode` | enum, `ErrorCode` | codes 1700–1799, prefix HTTP (ADR-003) |
| `Exception\InvalidMessageException` | final, Validation, `\InvalidArgumentException` | 1700–1712 |
| `Exception\InvalidUriException` | final, Validation, `\InvalidArgumentException` | 1720–1723 |
| `Exception\StreamException` | final, Runtime | 1730–1735 |
| `Exception\UploadedFileException` | final, Runtime | 1740–1742 |
| `Exception\EmitterException` | final, Runtime | 1750 |
| `Exception\InvalidConfigurationException` | final, Configuration | 1760–1766 |

## 3. Messages

- **Headers.** Names are RFC 9110 tokens (1700). A value is a string or a
  number, trimmed of spaces and tabs, without control characters other than
  tab (1701); a list keeps its order. Lookups ignore case; `withHeader()`
  replaces and takes the new spelling, `withAddedHeader()` appends.
- **Request line.** The method is a token and keeps its case (1702). The
  request target is one word without whitespace or controls (1704); without
  one, it is the URI's path (with a leading `/`) and query.
- **Host.** A request takes Host from its URI, first among the headers,
  unless a Host header was given. `withUri()` replaces Host unless asked to
  preserve a non-empty one.
- **Status.** 100–599 (1705); the reason phrase follows the field-value rule
  (1706) and defaults to the registered phrase.
- **Versions.** A digit, optionally `.` and a digit (1703).
- **Bodies.** A message without body gets an empty `php://temp` stream.

## 4. URIs

The scheme follows RFC 3986 §3.1 and is lower-cased (1721). The host is a
registered name, an IPv4 address or a bracketed IP literal, lower-cased
(1722). The port is 0–65535 (1723) and hidden when it is the scheme's default
(http, https, ws, wss, ftp). User info, path, query and fragment are
percent-encoded where they hold characters their component does not allow; an
existing escape is kept. `__toString()` adds `/` before a rootless path after
an authority and collapses leading slashes without one (PSR-7 §3.5). A string
`parse_url()` refuses is 1720.

## 5. Streams and uploads

- `Stream` is readable when the mode starts with `r` or has `+`, writable
  when it starts with `w`, `a`, `x` or `c` or has `+`. Operations a mode does
  not allow throw 1732 or 1733; a detached stream throws 1730; an impossible
  seek 1731; a failed seek, read or write 1734. `read(0)` returns `""`; a
  negative length is 1734. `__toString()` never throws.
- `UploadedFile` holds a stream or a temporary file path. `moveTo()` refuses
  an empty or NUL path (1709), a failed upload (1740), a second move (1741)
  and reports a failed copy or short write (1742). Under the CLI SAPI a
  temporary file is renamed; elsewhere `move_uploaded_file()` checks that PHP
  received it.
- `HttpFactory::createStreamFromFile()` refuses a mode PHP would misread
  (1712, `\InvalidArgumentException` as PSR-17 asks) and a file it cannot
  open (1735).

## 6. Requests from the server

`fromServer(array $server, ?StreamInterface $body)` builds the request line,
headers and URI from a `$_SERVER`-shaped array; `fromGlobals()` adds cookies,
query, parsed body (null for an empty `$_POST`) and uploads, with
`php://input` as body. Client input is treated as untrusted (ADR-005):

| Input | Rule |
|---|---|
| Host header | used only when it is `host[:port]` with port ≤ 65535; else `SERVER_NAME` and `SERVER_PORT` |
| scheme | `HTTPS` (not `off`) or `REQUEST_SCHEME`; never `X-Forwarded-*` |
| `REQUEST_URI` | origin form: path and query; absolute form: its path and query, not its authority; `*`: request target `*`; anything else: `/` |
| `HTTP_*`, `CONTENT_TYPE`, `CONTENT_LENGTH` | header fields; one that breaks the grammar is skipped |
| `REDIRECT_HTTP_AUTHORIZATION` | Authorization, when none was sent directly |
| `SERVER_PROTOCOL` | `HTTP/x[.y]`, else 1.1 |

## 7. Dispatch

`MiddlewareDispatcher::pipe(...$middleware)` appends; the first piped is the
outermost. `handle()` walks the queue through `MiddlewareStep` and ends in the
fallback handler. A step holds no mutable state, so a dispatcher can serve
concurrent requests once its queue is built.

## 8. CORS

`CorsConfiguration` (ADR-006): origins are `*` alone or serialised origins
(1761), no credentials with `*` (1760), max-age ≥ 0 (1762).
`CorsMiddleware` answers preflights from allowed origins with 204, adds
`Access-Control-Allow-Origin` (the origin, or `*` for a public policy),
credentials and exposed headers to other responses, adds `Vary: Origin` to
every response of a listed policy, and echoes announced request headers under
the wildcard header policy only when they form a list of field names.

## 9. Client certificates

`ClientCertificateMiddleware` with a `TlsConfiguration` (ADR-007):

```
source    SSL_CLIENT_* server variables; or X-SSL-Client-* headers when
          REMOTE_ADDR is a trusted proxy (server variables win). From
          other peers the headers are removed.
verify    SUCCESS: certificate; NONE or nothing: no certificate (403 when
          required); anything else: 403 verification failed.
policy    empty subject: 403 when required, else pass without it;
          validity period against the clock; DN allow-list (exact);
          pinned fingerprints (hex digits, hash_equals; a missing
          fingerprint fails).
result    attribute "client_certificate"; refusals 403 JSON.
```

`TlsConfiguration` validates the verify depth (≥ 1, 1765) and the trusted
proxies (1763), and gives `toStreamContextOptions()` for PHP servers that
terminate TLS themselves.

## 10. Router integration

`HttpNormalizer` maps a PSR-7 server request to the router's
`NormalizedRequest` (upper-cased method, path or `/`). `RouterRequestHandler`
matches, answers 404 and 405 (with Allow) as JSON, adds route parameters and
`_route` as attributes, resolves every route middleware name through its
resolver, throwing 1766 for one that is not a PSR-15 middleware, and runs
global middleware, route middleware and the handler through
`CallableHandler`: a response passes through; null is 204; a string is HTML;
an array or object is JSON; other scalars are text; a non-callable is 500.

## 11. Emitter

`SapiEmitter::emit()` refuses to run after output started (1750), sends the
status line with the response code, sends each header (the first value
replaces, the others add), rewinds a seekable body and echoes it in chunks of
the buffer size (default 8192, at least 1, else 1764) while the client is
connected; a body it cannot read is sent as its string form.

## 12. ARFA 1.3

| Principle | Where |
|---|---|
| P1 immutable state | messages, URIs, configurations, certificates, network ranges |
| P2 reactive flow | middleware queue, emitter streaming the body |
| P4 protocol agnosticism | `HttpNormalizer` keeps the router free of PSR-7 |
| P5 observability | codes in every exception (1700–1799) |
