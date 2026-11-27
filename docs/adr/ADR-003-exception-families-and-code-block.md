# ADR-003 — Exception Families and the Code Block 1700–1799

**Status:** Accepted
**Date:** 2026-11-27

## Context

The reviewed 4.0 had `HttpException` (with factories for 400, 404, 405 and
500 that nothing threw) and `InvalidHeaderException`, which nothing threw
either; invalid input passed through or failed as a generic SPL exception.
KaririCode Spec V4.3.3 §8.4 identifies a failure by its kariricode/contract
family plus a four-digit code from a block the ecosystem registry
(kariricode/exception, CODE-BLOCKS.md) gives one package.

PSR-7 adds a constraint: invalid arguments to messages, streams and URIs must
be reported as `\InvalidArgumentException`, failed stream operations as
`\RuntimeException`. kariricode/exception's `ValidationException` extends
`\DomainException`, which is not an `\InvalidArgumentException`.

## Decision

- The registry gives kariricode/http block 1700–1799 under the prefix HTTP;
  kariricode/exception 2.0.2 is the first release that lists it, so the
  constraint is `^2.0.2`.
- `HttpCode` (an `ErrorCode` enum) holds the codes:

  | Range | Area |
  |---|---|
  | 1700–1719 | message arguments: header name and value, method, protocol version, request target, status, reason phrase, uploaded files, parsed body, move target, stream resource, normalizer input, stream mode |
  | 1720–1729 | URIs: unparsable, scheme, host, port |
  | 1730–1739 | streams: detached, not seekable, not readable, not writable, operation failed, open failed |
  | 1740–1749 | uploaded files: upload error, already moved, move failed |
  | 1750–1759 | the SAPI emitter: headers already sent |
  | 1760–1779 | configuration: CORS, trusted proxies, buffer size, verify depth, unresolvable middleware |

- Six final classes, each in one family, each built by named constructors:
  `InvalidMessageException` and `InvalidUriException` (Validation),
  `InvalidConfigurationException` (Configuration), `StreamException`,
  `UploadedFileException` and `EmitterException` (Runtime).
- The four Runtime and Configuration classes extend the kariricode/exception
  bases. The two Validation classes extend `\InvalidArgumentException`, as
  PSR-7 asks, implement the `Validation` family and carry their code through
  the `CarriesErrorCode` trait. This is an accepted deviation, checked by the
  conformance suite.
- Messages are fixed text. A value in a message is an identifier the
  application chose (a header name, a middleware name, a proxy entry, a file
  name) and goes through `quote()`, which escapes control characters; a value
  the client sent is never repeated (errata E-047).
- The exceptions do not carry an HTTP status. Which status a failure maps to
  is the application's decision.

## Consequences

- PSR-7 consumers that catch `\InvalidArgumentException` or
  `\RuntimeException` keep working; KaririCode code catches the families.
- `HttpException` and `InvalidHeaderException` are gone (no release had
  them).
- The registry test reads CODE-BLOCKS.md from the installed
  kariricode/exception; the catalogue test pins one factory per code.

## Alternatives Considered

- **Extend `ValidationException` everywhere.** Rejected: PSR-7 consumers would
  miss the exceptions they are told to expect.
- **Exceptions that carry an HTTP status.** Rejected: most failures here are
  programming errors (a bad header from the application), and the same
  failure maps to different statuses in different places.

## References

- KaririCode Spec V4.3.3 §8.4; errata E-047.
- kariricode/exception ADR-001 (family bases), ADR-002 (registry) and the
  `CarriesErrorCode` trait.
- PSR-7 §1.5 and the interface docblocks (`@throws`).
- https://github.com/KaririCode-PHP/exception/blob/main/CODE-BLOCKS.md
