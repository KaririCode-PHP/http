# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [4.0.0] — 2026-11-27

First published release, under the package name kariricode/http (the
repository was a placeholder named http-handler). The code is the reviewed
4.0 (kariricode-http-v4.0, 2026-03-03), brought to KaririCode Spec V4.3.3
before publication; the fixes below were found on the way.

### Security

- **Header and response splitting.** Header names and values, methods,
  request targets and reason phrases were stored as given, so
  `withHeader('Location', "/x\r\nSet-Cookie: admin=1")` reached the client as
  two headers. They are now checked against RFC 9110 and RFC 9112 when they
  enter a message (1700–1706, ADR-005).
- **Host header injection.** `ServerRequest::fromGlobals()` copied `HTTP_HOST`
  into the URI, so `good.example@evil.example` or `evil.example/x` changed the
  authority. Host now counts only when it is `host[:port]`, and `Uri` refuses
  hosts that could move the authority (1722).
- **Client-certificate spoofing.** `ClientCertificateMiddleware` read the
  `X-SSL-Client-*` headers from every client, treated a missing
  `X-SSL-Client-Verify` as success and let a certificate without fingerprint
  pass fingerprint pinning. Headers now count only from configured trusted
  proxies and are removed for everyone else, verification must read
  `SUCCESS`, and pinning refuses a missing fingerprint (ADR-007).
- **DN spoofing.** `commonName()` read the subject with a regular
  expression, so `O=a\,CN=admin` gave `admin`. DNs are now parsed with RFC
  4514 escapes and quotes.
- **Certificates that never expired.** Dates went through
  `new \DateTimeImmutable()`, which accepts `now` and `+10 years`, and
  unreadable dates became 1970. Only the formats servers write are read now;
  unknown dates stay unknown, and the validity period is checked against an
  injected PSR-20 clock.
- **Credentialed CORS for every origin.** `allowedOrigins: ['*']` with
  credentials echoed any Origin with `Access-Control-Allow-Credentials:
  true`. That configuration is now refused (1760), origins must be
  serialised origins (1761), and listed policies send `Vary: Origin` on every
  response (ADR-006).
- **Fail-open route middleware.** `RouterRequestHandler` skipped a
  middleware name that did not resolve, so a typo removed `auth` from a
  route. It now throws 1766 (ADR-002).
- **Host reflected in redirects.** `TrailingSlashMiddleware` redirected to
  the full request URI, whose host comes from the Host header. The Location
  is now a path, with leading slashes collapsed so `//evil.example/` cannot
  become protocol-relative.
- `UploadedFile::moveTo()` used `rename()` under the built-in web server
  (`cli-server`), skipping `move_uploaded_file()`'s check; it now renames
  only under the CLI, and refuses empty or NUL target paths (1709).

### Fixed

- `Stream` decided readable and writable from a fixed table of modes, so
  modes PHP accepts outside it (`rb+`, `re`, `wbe`) were neither. Abilities
  now follow PHP's own reading of the mode: the first letter, plus `+`.
- `Stream::read(0)` threw PHP's `ValueError` and negative lengths reached
  `fread()`; `read(0)` returns `""` and a negative length throws 1734.
- A failed read or write let PHP's warning escape before the exception; it
  is now only the exception.
- `UploadedFile` copied a stream without checking short writes; a full disk
  produced a truncated file and no error.
- `ServerRequest` accepted any value as uploaded files or parsed body.
- `HttpFactory::createStreamFromFile()` opened `rw` read-only without a word,
  reported an invalid mode as a file it could not open, and failed with a
  `ValueError` on empty or NUL file names. It now throws 1712
  (`\InvalidArgumentException`, as PSR-17 asks) for a mode PHP would misread
  and 1735 when the file cannot be opened.
- `Uri` did not encode user info, and its withers rebuilt the whole string
  and parsed it again, so one component could change another:
  `withHost('evil.example/x')` moved part of the host into the path. Withers
  now replace one checked component.
- ADR-002 and the README described a PSR-11 container the code never used.
- `StatusCode::phrases()` memoised its table in a static variable; the enum
  is now backed by the code (errata E-003) and keeps no state.
- README examples used classes and arguments that did not exist
  (`Security\CorsConfiguration`, a container argument to
  `MiddlewareDispatcher`, a normalizer argument to `RouterRequestHandler`).

### Changed

- **Breaking.** `HttpException` and `InvalidHeaderException`, which nothing
  threw, are replaced by six final classes in kariricode/contract families
  with codes 1700–1799 under the prefix HTTP (ADR-003).
- **Breaking.** `StatusCode` is a backed enum; `PayloadTooLarge` and
  `UnprocessableEntity` are `ContentTooLarge` and `UnprocessableContent`,
  with the RFC 9110 phrases; 208 and 226 were added.
- **Breaking.** `ClientCertificate` dates are nullable, and `isExpired()`,
  `isNotYetValid()` and `isWithinValidityPeriod()` take the current time.
  `ClientCertificateMiddleware` takes an optional PSR-20 clock.
- **Breaking.** `RouterRequestHandler` takes a `Closure` resolver for route
  handlers and middleware names.
- `TlsConfiguration` gained `trustedProxies`, `withTrustedProxies()` and
  `isTrustedProxy()`, validates the verify depth (1765) and captures the
  peer certificate whenever it verifies peers (the optional mode left it
  out, so PHP servers saw no certificate there).
- `SystemClock` reads the time in UTC.
- Host is placed first among the headers (RFC 9112 §3.2).

### Added

- `ServerRequest::fromServer()` builds a request from a `$_SERVER`-shaped
  array; `UploadedFile::treeFromGlobals()` reads `$_FILES`, including
  multiple and nested uploads.
- `NetworkRange` and `DistinguishedName` value objects.
- Spec V4.3.3 toolchain through KaririCode Devkit, CI, conformance and
  registry tests, SBOM, ADR-001 to ADR-007, SPEC-001, the quality report and
  the security policy.

[Unreleased]: https://github.com/KaririCode-PHP/http/compare/v4.0.0...HEAD
[4.0.0]: https://github.com/KaririCode-PHP/http/releases/tag/v4.0.0
