# ADR-007 — Client-Certificate Trust: Believe Only What the Client Cannot Write

**Status:** Accepted
**Date:** 2026-11-27

## Context

With mutual TLS, the TLS layer verifies the client's certificate and the
application reads the result. PHP never sees the handshake when a web server
or a reverse proxy terminates TLS: the web server exports `SSL_CLIENT_*`
variables, and a proxy forwards headers such as `X-SSL-Client-Verify` and
`X-SSL-Client-DN`.

The reviewed 4.0 read those headers from every request. Any client could send
`X-SSL-Client-DN: CN=admin` and be treated as authenticated; a missing
`X-SSL-Client-Verify` counted as success; a certificate without a fingerprint
passed fingerprint pinning; `CN` was read with a regular expression, so
`O=a\,CN=admin` yielded `admin`; dates went through `new \DateTimeImmutable()`,
which accepts `now` and `+10 years`, and unknown dates became 1970, so a
certificate with an invalid date never expired.

## Decision

- Two sources only. The web server's variables (`SSL_CLIENT_VERIFY` and the
  other `SSL_CLIENT_*` parameters) count always. The `X-SSL-Client-*` headers
  count only when the TCP peer (`REMOTE_ADDR`) is one of
  `TlsConfiguration::$trustedProxies` (addresses or CIDR ranges, compared as
  packed bytes by `NetworkRange`); when the server variables are present they
  win.
- From any other peer the `X-SSL-Client-*` headers are removed before the
  request goes on, so no handler can read a forged value.
- Verification must read `SUCCESS`. `NONE` or nothing means no certificate
  (403 when required, passed through otherwise); `FAILED`, `GENEROUS` or any
  other value is a failed verification (403 in every mode).
- With pinned fingerprints, a certificate without a fingerprint is refused;
  fingerprints compare hex digits only, with `hash_equals()`. When the PEM is
  available and ext-openssl is loaded, the fingerprint is computed from it
  instead of taken from a header.
- `DistinguishedName` reads RFC 4514 strings with escapes, quotes and
  multi-valued RDNs, and the OpenSSL one-line form; an escaped separator stays
  inside its value.
- Dates are read only in the formats servers write (OpenSSL's
  `Nov 27 12:00:00 2027 GMT`, ISO 8601); anything else stays unknown. The
  validity period is checked against an injected PSR-20 clock when a date is
  known.
- The verified certificate goes into the request attribute
  `client_certificate`; refusals are 403 with a JSON body.

## Consequences

- Without trusted proxies, only TLS terminated by the web server counts; a
  deployment behind a proxy must list it, and the proxy must overwrite the
  headers it forwards.
- The DN allow-list compares the subject string exactly, in the format the
  server reports (RFC 4514 for nginx, the one-line form for Apache by
  default).
- A certificate with unknown dates passes the validity check; the TLS layer
  already checked them during the handshake.
- `commonName()` and `organization()` trim spaces at either end of a value,
  escaped or not.

## Alternatives Considered

- **Trust the headers from everyone.** Rejected: that is the vulnerability.
- **Trust `X-Forwarded-For` to find the peer.** Rejected: it is another header
  the client writes; `REMOTE_ADDR` is the TCP peer the server saw.
- **Parse DNs with regular expressions.** Rejected: escapes and quotes make
  the grammar context-sensitive.

## References

- RFC 8446 §4.4.2 (client Certificate); RFC 5280; RFC 4514; RFC 4632 (CIDR).
- Apache mod_ssl environment variables; nginx ngx_http_ssl_module
  (`$ssl_client_verify`, `$ssl_client_escaped_cert`).
- OWASP Transport Layer Security Cheat Sheet; CWE-290 (authentication bypass
  by spoofing).
- PSR-20 (Clock).
