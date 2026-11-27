# ADR-001 — PSR-7, PSR-15 and PSR-17 Messages, Immutable by Construction

**Status:** Accepted
**Date:** 2026-11-27 (first written 2026-03-03)

## Context

PHP frameworks and libraries exchange HTTP messages through PSR-7 (messages),
PSR-15 (handlers and middleware) and PSR-17 (factories). A message library
that ignores them forces adapters on every integration. PSR-7 also fixes the
programming model: every `with*()` method returns a new message, so a message
can pass through a middleware queue without defensive copies (ARFA 1.3 P1).

The reviewed 4.0 implemented the interfaces but stored whatever it was given:
header values with CR or LF, any method string, any status code. Its header
trait (`HasHeaders`) existed in three message classes with diverging copies
of the same rules, and `StatusCode::phrases()` memoised its table in a static
variable.

## Decision

- One class per PSR-7 interface, all final: `Request`, `Response`,
  `ServerRequest`, `Stream`, `Uri`, `UploadedFile`. One PSR-17 class,
  `HttpFactory`, implements the six factory interfaces.
- Two traits hold the shared message state, so the rules exist once:
  `MessageState` (protocol version, headers, body) and `RequestLine`
  (method, request target, URI, Host). `HttpGrammar` holds the RFC 9110 and
  RFC 9112 character rules both use (ADR-005).
- Messages validate on construction and in every `with*()`; a wither works on
  a clone, so the original never changes. The body stream is shared between
  copies, as PSR-7 intends.
- `Stream` reads its abilities from the open mode as PHP does (the first
  letter, plus `+`), owns its resource, and closes it in `close()` and in the
  destructor. `detach()` hands the resource back to the caller.
- `StatusCode` is a backed enum (errata E-003): the code is an external
  contract the IANA registry fixes, so `tryFrom()` replaces the memoised
  table, and no static state remains. Phrases follow RFC 9110 where it
  renamed a status (413, 422).
- `MiddlewareDispatcher` is a PSR-15 handler with a queue of middleware in
  front of a fallback handler; `MiddlewareStep` is the immutable step that
  walks the queue, so a dispatcher can be called again and from several
  fibers.
- `SapiEmitter` sends a response through `header()` and `echo`, reading the
  body in chunks while the client is connected.

## Consequences

- Any PSR-7/15/17 consumer accepts these objects without adapters.
- Spec V4.3.3 §18 forbids zero-argument getters and magic methods. The
  `get*()` methods, `__toString()` of `Uri` and `Stream`, and
  `Stream::__destruct()` (resource ownership) are accepted deviations, listed
  in `tests/Conformance/SpecConformanceTest.php` and the quality report.
- `Uri`, `Stream` and `ServerRequest` keep private, non-readonly state
  because PSR-7 withers clone and assign; nothing outside the class can write
  it.
- Each `with*()` allocates a message. On hot paths that is the price of
  sharing messages safely.

## Alternatives Considered

- **Readonly message classes.** Rejected: a wither then has to rebuild the
  whole object through the constructor, repeating every validation, and
  `Stream` has state (the resource) that changes by nature.
- **Mutable messages with explicit copies.** Rejected: PSR-7 consumers rely on
  immutability; a middleware that changes a shared request would affect the
  others.
- **Keep the per-class header code.** Rejected: three copies had already
  drifted apart.

## References

- PSR-7: https://www.php-fig.org/psr/psr-7/
- PSR-15: https://www.php-fig.org/psr/psr-15/
- PSR-17: https://www.php-fig.org/psr/psr-17/
- RFC 9110 §15 (status codes); IANA HTTP Status Code Registry.
- ARFA 1.3 P1; KaririCode Spec V4.3.3 §18; errata E-003.
