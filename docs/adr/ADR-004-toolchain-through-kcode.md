# ADR-004 — Quality Gates through KaririCode Devkit, with the Root Configs

**Status:** Accepted
**Date:** 2026-11-27

## Context

The reviewed 4.0 declared PHPUnit in `require-dev`, had no other gate and no
CI. KaririCode Spec V4.3.3 §29.1 asks for style, PHPStan at level max, Psalm
at level 1, complexity budgets, tests, a mutation score of at least 80 and a
security check; §32 asks for a committed lock, an SBOM and CI actions pinned
by commit SHA. The ecosystem installs its toolchain with KaririCode Devkit,
whose kcode 1.2.1 runs a component's committed configs when `devkit.php` sets
`'root_configs' => true`.

## Decision

- No `require-dev`. `devkit.php` pins PHPUnit 11.5.56, PHPStan 2.3.7 with
  tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer
  3.95.27 and Infection 0.35.5; kcode installs them into `.kcode/`.
- The configs live at the root (`phpstan.neon`, `psalm.xml`,
  `phpunit.xml.dist`, `infection.json5`, `.php-cs-fixer.dist.php`) and point
  into `.kcode/vendor/`. `tools/complexity.php` checks the §13 budgets,
  `tools/secret-scan.php` the tracked files, `tools/sbom.php` writes a
  CycloneDX 1.5 SBOM.
- `composer check` runs every gate in CI order; `composer security` audits
  the runtime lock and the toolchain lock.
- `composer.lock` is committed with the runtime only.
- CI installs kcode by version and SHA-256, runs the gates on PHP 8.4 and the
  suites on PHP 8.5 with the lowest dependencies the constraints allow.
- Under the CLI the SAPI keeps no header list, so the tests load
  `tests/Support/sapi-functions.php` (autoload-dev `files`): it defines
  `header()`, `headers_sent()` and `connection_status()` in the emitter's
  namespace and records the calls. The emitter calls them unqualified for
  that reason; nothing changes for a production install.

## Consequences

- The same gates as kariricode/router, exception, sse and console, with the
  same commands.
- `composer install` alone does not bring the tools; contributors install
  kcode once (README, CONTRIBUTING).
- The emitter's header lines, status code and connection checks are tested
  byte for byte without a web SAPI.

## Alternatives Considered

- **Tools in `require-dev`.** Rejected: their dependencies would enter the
  component's lock and audit surface, and versions would drift between
  components.
- **kcode's generated configs.** Rejected: the committed configs are what the
  Spec and the conformance tests read.
- **Inject a header writer into `SapiEmitter`.** Rejected: a constructor
  argument that only tests use; the namespace fallback gives the same
  coverage.

## References

- KaririCode Spec V4.3.3 §13, §29.1, §32.
- kariricode/router ADR-008 and kariricode/sse ADR-004 (the same move).
- PHP manual, "Using namespaces: fallback to global function".
- https://cyclonedx.org/specification/overview/
