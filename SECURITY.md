# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | yes |

## Reporting a vulnerability

Report it privately through GitHub's
[security advisories](https://github.com/KaririCode-PHP/http/security/advisories/new),
or by e-mail to walmir.silva@kariricode.org. Please do not open a public
issue.

## Threat model

The package sits on the boundary where client bytes become PHP objects and
PHP objects become response bytes.

| Threat | Defence |
|---|---|
| Response splitting / header injection: CR or LF in a header value, method, request target or reason phrase starts a field or a response of its own | every value is checked when it enters a message: tokens for names and methods, the RFC 9110 field-value rule for values and reason phrases, one word for request targets (1700–1706, ADR-005) |
| A forged Host header changes the URI's authority (`good.example@evil.example`, `evil.example/x`) | `fromGlobals()` uses Host only when it is `host[:port]`; `Uri` refuses hosts with `@`, `/`, `?`, `#` or `:` (1722) |
| A client claims HTTPS with `X-Forwarded-Proto` | the scheme comes from `HTTPS` or `REQUEST_SCHEME` only |
| A client forges `X-SSL-Client-DN: CN=admin` and passes as authenticated | proxy headers count only from `TlsConfiguration::$trustedProxies` (by `REMOTE_ADDR`) and are removed for everyone else; verification must read `SUCCESS` (ADR-007) |
| Pinning bypass with a certificate that has no fingerprint | refused when fingerprints are pinned; fingerprints compare with `hash_equals()` and are computed from the PEM when ext-openssl is loaded |
| DN spoofing through escapes (`O=a\,CN=admin`) | `DistinguishedName` reads RFC 4514 escapes and quotes; an escaped separator stays in its value |
| Certificates that never expire through unreadable or relative dates (`now`, `+10 years`) | only the formats servers write are read; the validity period is checked against an injected clock |
| Credentialed CORS readable by any site | credentials with `*` are refused (1760); origins must be serialised origins (1761); listed policies send `Vary: Origin` on every response (ADR-006) |
| A route loses its guard when a middleware name does not resolve | `RouterRequestHandler` throws 1766 instead of skipping it (ADR-002) |
| Open redirect through the trailing-slash redirect (`//evil.example/`, forged Host) | the Location is a path with leading slashes collapsed; it never names a host |
| An uploaded file path with NUL or an empty target | refused (1709); outside the CLI, `move_uploaded_file()` checks PHP received the file |
| Client data in logs | exception messages are fixed text; identifiers the application chose go through `quote()` (E-047) |

Out of scope, by design:

- Request body parsing beyond PHP's `$_POST`, size limits and rate limits:
  the web server and the application set them.
- HTML escaping of what a handler returns as a string: `CallableHandler`
  sends it as given.
- Trusting proxies for client addresses or schemes (`X-Forwarded-For`,
  `Forwarded`): an application that needs it decides which proxies to trust.
- TLS itself: the web server, proxy or PHP stream verifies the chain; this
  package reads the result.

## ASVS 5.0, level 1

The relevant requirements are HTTP request and response integrity (no header
injection), output encoding of headers, CORS configuration, and
authentication by client certificates without trusting client-supplied
headers; the threat model above maps them. The package stores no
credentials and keeps no sessions.
