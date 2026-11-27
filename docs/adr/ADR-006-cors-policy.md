# ADR-006 — CORS Policy: No Wildcard with Credentials, Vary Everywhere

**Status:** Accepted
**Date:** 2026-11-27

## Context

CORS lets a page on one origin read responses from another. With
credentials (cookies, HTTP authentication), a response readable by an
attacker's page leaks the user's data. The Fetch Standard therefore refuses
`Access-Control-Allow-Origin: *` together with credentials, and servers that
want both often "fix" it by echoing the request's Origin, which allows every
site.

The reviewed 4.0 did exactly that: `allowedOrigins: ['*']` with
`allowCredentials: true` echoed any Origin with
`Access-Control-Allow-Credentials: true`. It accepted any string as an origin
(`null`, paths, patterns that never match), added `Vary: Origin` only to
allowed responses, so a cache could serve one origin's answer to another, and
echoed the announced request headers into `Access-Control-Allow-Headers`
without checking them.

## Decision

- `CorsConfiguration` refuses credentials with the wildcard origin (1760), in
  the constructor and in `withCredentials()`. Credentialed CORS needs a list
  of origins.
- An origin entry is `"*"` alone or a serialised origin,
  `scheme://host[:port]` (1761); `null`, paths, user info and patterns are
  refused. Origins compare without regard to case.
- `maxAge` is zero or more (1762); zero sends no `Access-Control-Max-Age`.
- `CorsMiddleware` answers a preflight (OPTIONS with
  `Access-Control-Request-Method`) from an allowed origin with 204, the
  allowed methods and headers and the max-age; a disallowed method gets a 204
  without CORS headers. Other requests reach the handler and the response
  gains `Access-Control-Allow-Origin` (the origin, or `*` for a public
  policy), credentials and exposed headers as configured.
- With a list of origins every response carries `Vary: Origin`, also those
  for unlisted origins and requests without Origin. A public policy answers
  every origin alike and adds no Vary.
- With the wildcard header policy, the announced request headers are echoed
  only when they form a list of field names.

## Consequences

- The dangerous configuration fails at bootstrap instead of shipping.
- `CorsConfiguration::singleOrigin()` is the credentialed setup;
  `permissive()` is a public API without credentials.
- Shared caches keep one answer per Origin for credentialed or listed
  policies.

## Alternatives Considered

- **Echo the Origin when credentials meet the wildcard.** Rejected: that is
  the vulnerability.
- **Origin patterns (`https://*.example.com`).** Rejected for now: the
  matching rules are where CORS bypasses usually live (suffix matches,
  unescaped dots). An explicit list is reviewable.
- **Vary only on allowed responses.** Rejected: a cached refusal or a cached
  answer without CORS headers would be served to an allowed origin, and the
  reverse.

## References

- Fetch Standard §3.2 (CORS protocol), §3.2.5 (credentials), §3.2.3.
- RFC 6454 §6.2 (origin serialisation); RFC 9110 §12.5.5 (Vary).
- OWASP HTML5 Security Cheat Sheet (CORS); PortSwigger research on CORS
  misconfigurations.
