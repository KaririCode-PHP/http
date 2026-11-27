# ADR-005 — Message Validation: Nothing Reaches the Wire That Could Split It

**Status:** Accepted
**Date:** 2026-11-27

## Context

An HTTP message is text in which CR LF ends a field and a blank line ends
the head. A header value, method, request target or reason phrase that holds
CR or LF can start a field or a response of its own (response splitting,
header injection, CWE-113). The reviewed 4.0 stored every value as given:
`withHeader('Location', $url)` with `"\r\nSet-Cookie: ..."` inside reached the
emitter intact.

`ServerRequest::fromGlobals()` has the opposite problem: its input comes from
the client. The reviewed 4.0 copied `HTTP_HOST` into the URI, so a Host of
`good.example@evil.example` or `evil.example/x` changed the URI's authority,
and it failed nowhere on odd header fields.

## Decision

Messages built by the application fail fast (`InvalidMessageException`,
`InvalidUriException`):

| Element | Rule | Code |
|---|---|---|
| header name | RFC 9110 token | 1700 |
| header value | string or number; after trimming spaces and tabs, no control characters except tab (RFC 9110 §5.5, obs-text allowed); an empty list is not a value | 1701 |
| method | token; case kept (methods are case-sensitive) | 1702 |
| protocol version | a digit, optionally `.` and a digit | 1703 |
| request target | one word: no whitespace, no controls | 1704 |
| status | 100–599 | 1705 |
| reason phrase | field-value rule | 1706 |
| uploaded files | a tree of `UploadedFileInterface` | 1707 |
| parsed body | array, object or null | 1708 |
| move target | not empty, no NUL | 1709 |
| file mode (PSR-17) | `r`, `w`, `a`, `x` or `c`, then `+`, `b`, `t`, `e`, `n` | 1712 |
| URI scheme | RFC 3986 §3.1, lower-cased | 1721 |
| URI host | registered name, IPv4, or bracketed IP literal, lower-cased: `@`, `/`, `?`, `#` and `:` cannot move the authority | 1722 |
| URI port | 0–65535 | 1723 |

User info, path, query and fragment are percent-encoded where they hold
characters their component does not allow, without encoding an existing
escape twice. A path that starts with `//` loses the extra slashes when the
URI has no authority, so it cannot be read as one.

Requests built from the server are lenient where the client wrote the value
and strict where it would change meaning:

- the Host header counts only when it is `host[:port]` (port ≤ 65535);
  otherwise `SERVER_NAME` and `SERVER_PORT` apply;
- the scheme comes from `HTTPS` or `REQUEST_SCHEME`, which the server sets;
  `X-Forwarded-Proto` and its kin are ignored;
- an absolute-form target gives its path and query, never its authority; the
  asterisk form stays the request target;
- header fields that break the grammar are skipped instead of failing the
  request; `REDIRECT_HTTP_AUTHORIZATION` fills a missing Authorization.

## Consequences

- Response splitting through this library needs a bug in it, not a value the
  application forgot to check.
- Values from a client must be checked before they go into a header the
  application builds; the exception tells the application, and its message
  names the header but never repeats the value (E-047).
- An application behind a proxy that terminates TLS must map the proxy's
  scheme into `HTTPS` itself, under its own trust decision.
- Some clients send header bytes this library skips; they reach the
  application as absent headers.

## Alternatives Considered

- **Strip CR and LF.** Rejected: silently changing a value hides the bug that
  produced it, and what remains may still be wrong.
- **Fail requests with malformed header fields.** Rejected: one odd header
  from a client or proxy would turn every request into a 500; the web server
  already accepted the request.
- **Trust `X-Forwarded-*`.** Rejected: any client can send them; trust needs a
  configured list of proxies, which belongs to the application or a
  dedicated middleware.

## References

- RFC 9110 §5.1, §5.5, §5.6.2, §7.2, §9.1, §15; RFC 9112 §3, §4; RFC 3986
  §2.1, §3.
- CWE-113 (HTTP response splitting); OWASP HTTP Headers Cheat Sheet.
- PSR-7 §1.2 and §3.5.
