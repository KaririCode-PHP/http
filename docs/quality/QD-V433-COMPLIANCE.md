# QD-V433: kariricode/http 4.0.0 — compliance with Spec V4.3.3

**Status:** Measured · **Date:** 2026-11-27 · PHP 8.4.28, PHPUnit 11.5.56, PHPStan 2.3.7, Psalm 6.19.1, PHP-CS-Fixer 3.95.27, Infection 0.35.5 (all pinned in `devkit.php`, ADR-004)

Reproduce with `composer check` and `composer security`; each number below
comes from that run. Runtime: kariricode/contract 2.12.1, kariricode/exception
2.0.2, kariricode/router 4.1.0, psr/clock 1.0.0, psr/http-factory 1.1.0,
psr/http-message 2.0, psr/http-server-handler 1.0.2,
psr/http-server-middleware 1.0.2. The suites also pass with
`composer update --prefer-lowest`.

## 1. Gates (§29.1)

| Gate | Command | Result |
|---|---|---|
| Style | `composer cs-check` (PER-CS 2.0) | pass: 0 files to fix |
| Static analysis | `composer phpstan` (level max; src, tests, tools) | pass: 0 errors |
| Static analysis | `composer psalm` (errorLevel 1) | pass: 0 errors |
| Complexity | `composer complexity` | pass: 271 methods within budget, 5 accepted parameter-count breaches (PSR-7 and PSR-17 signatures, `tools/complexity-baseline.json`); cognitive complexity 0 errors |
| Unit | `composer test-unit` | 576 tests, 2036 assertions, all pass |
| Conformance | `composer test-conformance` | 20 tests, 1568 assertions, all pass |
| Coverage | PHPUnit with pcov | lines 99.77% (876 of 878), methods 99.26% |
| Mutation | `composer mutation` (MSI and covered MSI ≥ 80) | pass: MSI 98.87%, covered MSI 98.87% (1242 mutants: 1195 killed, 22 errors, 11 time-outs, 14 escaped) |
| Security | `composer security` | `composer audit --locked`: 0 advisories (runtime and toolchain); secret scan: clean |
| SBOM | `composer sbom` | CycloneDX 1.5, 8 runtime components |

The 14 escaped mutants are equivalent or unreachable:

- 5 in `StatusCode`: `intdiv($code, 99)` gives the same class as
  `intdiv($code, 100)` for every registered code.
- 3 replace an out-of-range sentinel or a chunk size by a neighbour
  (`ServerRequest` port 65536, `UploadedFile` chunk 8192).
- 2 remove a `throw` after `ftell()` and `stream_get_contents()`, which
  return `false` only for failures PHP cannot be made to produce in a test.
- 1 removes the `finally` that closes a copied file; PHP closes the local
  resource when the function returns.
- 2 in `ClientCertificate` change the branch for a missing ext-openssl, which
  the suite cannot unload.
- 1 drops a null-safe call on a proxy entry the constructor already
  validated.

## 2. Rules (§29.2), checked by `tests/Conformance`

| Rule | Result |
|---|---|
| One type per file, strict types, namespace | 35 of 35 types |
| Classes final | 30 of 30 classes |
| Exception families (§8.4) | 6 of 6 classes, one family each; four on kariricode/exception bases, two on `\InvalidArgumentException` (deviation 1); named constructors only |
| Codes (registry 1700–1799, prefix HTTP) | 34 codes, unique, in the documented ranges, one factory each; the row in CODE-BLOCKS.md of kariricode/exception 2.0.2 |
| No echo of input (E-047) | fixed messages; identifiers through `quote()`; no interpolation in exception classes |
| Zero-argument getters, setters, magic methods (§18) | 0 beyond the PSR-7 contract and `Stream::__destruct()` (deviation 2) |
| Static or writable public state (§18) | 0 |
| Untyped constants | 0 |
| Error suppression, eval, debug output | 0 (`Message\Silently` scopes an error handler instead of `@`) |
| Docblocks C1–C5 (§17) | 35 of 35 types |
| ADRs with the canonical sections | 7 of 7 |
| Imports (§31) | kariricode/router only in `src/Integration` |

## 3. Accepted deviations

| # | Item | Reason |
|---|---|---|
| 1 | `InvalidMessageException` and `InvalidUriException` extend `\InvalidArgumentException` and use `CarriesErrorCode` | PSR-7 asks for `\InvalidArgumentException`; `ValidationException` is a `\DomainException` (ADR-003) |
| 2 | PSR-7 `get*()` methods, `__toString()` of `Uri` and `Stream`, `Stream::__destruct()` | the PSR-7 contract; the stream owns its resource (ADR-001) |
| 3 | `object` parameters of `HttpNormalizer::canNormalize()` and `normalize()` | the signatures of kariricode/router's `RequestNormalizer` (its ADR-002) |
| 4 | Five constructors and one factory method above four parameters | the fields PSR-7 and PSR-17 define, passed by name (`tools/complexity-baseline.json`) |
| 5 | Soft budget of ten public methods exceeded by the PSR-7 classes, `TlsConfiguration`, `CorsConfiguration` and `InvalidMessageException` | the interfaces and the withers of the value objects; one named constructor per code |
| 6 | `SapiEmitter` calls `header()`, `headers_sent()` and `connection_status()` unqualified | the tests define them in the emitter's namespace to observe the SAPI under the CLI (ADR-004) |

## 4. Open items

1. The release tag is created by the GitHub release form and is not signed
   (§32); signing needs the maintainer's key.
2. Trusting proxies for client addresses and schemes (`Forwarded`,
   `X-Forwarded-*`) is left to the application or a future middleware
   (ADR-005).
3. CORS origin patterns are not supported; origins are listed (ADR-006).
