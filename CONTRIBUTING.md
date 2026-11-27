# Contributing

## Gates

```bash
composer install     # from composer.lock: kariricode/exception, router, contract, the PSR interfaces
kcode init           # install the toolchain pinned in devkit.php into .kcode/
composer check       # cs-check, phpstan (max), psalm (1), complexity, the suites, mutation
composer security    # audit of the runtime and the toolchain, secret scan
composer sbom        # CycloneDX 1.5 SBOM
```

[KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs the tools and runs this repository's configs (ADR-004). Install 1.2.1 and check it against its published SHA-256. Mutation testing needs pcov or Xdebug; the certificate tests need ext-openssl.

## Code rules (KaririCode Spec V4.3.3)

- **Files.** `declare(strict_types=1)`, one type per file, PSR-4, PER Coding Style.
- **Type docblocks.** Each states the domain role, its preconditions, thread-safety and side effects, a usage example, references and an `@see` link (§17). The conformance suite checks the markers.
- **Prohibited.** Zero-argument getters, setters, `toArray()`/`fromArray()`, magic methods and static state, except what PSR-7 requires (its `get*()` methods, `__toString()`) and `Stream::__destruct()`; `object` parameters beyond the router's `RequestNormalizer` signatures; error suppression with `@` (use `Message\Silently`). kariricode/router may be imported only in `src/Integration`.
- **Wire format.** No value may enter a message unchecked (ADR-005). A new header-like field gets tests with CR, LF, NUL and DEL.
- **Trust.** Values the client writes (headers, Host, the request target) never decide security on their own: client certificates need a trusted peer (ADR-007), CORS needs explicit origins for credentials (ADR-006).
- **Complexity.** Cyclomatic ≤ 10, cognitive ≤ 15, nesting ≤ 3, parameters ≤ 4 (§13). `tools/complexity-baseline.json` lists accepted breaches with their reasons; it may only shrink.
- **Exceptions.** A failure is a final class in a kariricode/contract family, built by a named constructor with an `HttpCode` case (ADR-003). Messages never repeat a value the client controls; identifiers go through `quote()`. A new code takes the next free number in its range.
- **Tests.** Every bug fix ships with a test that fails without it. `tests/Support` has a fixed clock, a recording handler and stream, a failing stream wrapper, a test certificate and a recorder for the emitter's SAPI calls.

## Decisions and releases

- **ADRs** go in `docs/adr/` with Status, Date, and the sections Context, Decision, Consequences, Alternatives Considered and References, in that order.
- **Commits** follow Conventional Commits.
- **Releases** follow Semantic Versioning. Tags should be signed (`git tag -s`); releases created from the GitHub UI are not, for now.
