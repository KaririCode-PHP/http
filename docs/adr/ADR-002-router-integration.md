# ADR-002 — Router Integration, Fail-Closed Middleware and Handler Results

**Status:** Accepted
**Date:** 2026-11-27 (first written 2026-03-03)

## Context

kariricode/router matches a method and a path and knows nothing of PSR-7
(its ADR-002). An HTTP application needs the opposite direction too: a PSR-15
handler that asks the router, runs the matched route's middleware and calls
its handler. Route middleware is named in the route table (`'auth'`,
`'throttle'`) and has to be turned into objects at dispatch time.

The reviewed 4.0 resolved names through a container and skipped any name that
did not resolve to a PSR-15 middleware. A typo in a route table, or a service
missing from a container, removed authentication from that route without a
trace. It also described a PSR-11 dependency the code did not have.

## Decision

- `HttpNormalizer` implements the router's `RequestNormalizer`: it reads the
  method (upper-cased) and the path (`/` when empty) of a PSR-7 server request
  and keeps the request as `original`. Anything else is a programming error
  (1711).
- `RouterRequestHandler` takes the router and one `Closure` resolver. It
  matches the request, answers 404 or 405 (with `Allow`) as JSON, adds the
  route parameters and `_route` as request attributes, and runs the global
  middleware (`pipe()`), then the route's middleware, in front of the handler.
- Every middleware name goes through the resolver. A result that is not a
  `MiddlewareInterface` throws `InvalidConfigurationException` 1766: the
  request fails instead of running without its guard.
- `CallableHandler` adapts a route handler: a response passes through, `null`
  is 204, a string is an HTML page, an array or object is JSON, any other
  scalar is text; a value that is not callable is a 500.
- `TrailingSlashMiddleware` removes trailing slashes before routing: GET and
  HEAD get a 301 to the trimmed path, other methods continue with the path
  rewritten. The Location is a path, never a URI, so it names no host.

## Consequences

- The router stays free of PSR-7; only `src/Integration` imports it, and the
  conformance suite checks that.
- A wiring mistake shows on the first request, as an exception with code 1766
  naming the middleware, instead of a silently weaker route.
- The resolver is plain PHP: a container lookup, a `match`, or a map.
- Global middleware runs for matched routes only. CORS preflights for paths
  without an OPTIONS route need a `MiddlewareDispatcher` in front of the
  router handler.
- A string result is sent as HTML as given; the handler escapes what it
  interpolates.

## Alternatives Considered

- **Skip middleware that does not resolve.** Rejected: a security control
  must not disappear because of a typo.
- **Depend on PSR-11.** Rejected: one closure covers containers and simpler
  setups without a new dependency.
- **Absolute Location headers in the trailing-slash redirect.** Rejected: the
  host would come from the Host header, which the client controls.

## References

- kariricode/router ADR-002 (RequestNormalizer) and its `Router` contract.
- PSR-15: https://www.php-fig.org/psr/psr-15/
- RFC 9110 §15.5.5 (404), §15.5.6 (405 and Allow), §15.4.2 (301), §10.2.2
  (Location).
- OWASP Unvalidated Redirects and Forwards Cheat Sheet.
