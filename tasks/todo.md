# KaririCode\Http V4.0 — ARFA 1.3 / Spec V4.0

## Status: COMPLETE

## Conformance

- PSR-7 (HTTP Messages) ✅
- PSR-15 (Handlers/Middleware) ✅
- PSR-17 (Factories) ✅
- ARFA 1.3 P1 (Immutable State) ✅ — all with* return new instances
- ARFA 1.3 P4 (Protocol Agnostic) ✅ — via Router RequestNormalizer
- Spec V4.0: PHP 8.4+, zero antipatterns, readonly where possible ✅
- Router V4.1 integration via HttpNormalizer + RouterRequestHandler ✅

## Spec V4.0 Audit: ZERO violations

- Zero "Gets the X" / "Gets or sets"
- Zero "Executes the X operation"
- Zero "Represents a X"
- Zero toArray/fromArray
- Zero backed enums (StatusCode is pure enum)
- Zero deep inheritance
- Zero tautological docs

## Structure (16 src + 7 test = 23 PHP files)

```
src/                                    # 2300 lines
├── Emitter/
│   └── SapiEmitter.php                # Response output via PHP SAPI
├── Enum/
│   └── StatusCode.php                 # Pure enum, RFC 9110, all codes
├── Exception/
│   ├── HttpException.php              # Base + static factories
│   └── InvalidHeaderException.php     # Header validation
├── Factory/
│   └── HttpFactory.php                # Unified PSR-17 (all 6 interfaces)
├── Handler/
│   └── MiddlewareDispatcher.php       # PSR-15 onion dispatcher
├── Integration/
│   ├── HttpNormalizer.php             # PSR-7 → Router V4.1 bridge
│   └── RouterRequestHandler.php       # PSR-15 handler backed by Router
├── Message/
│   ├── HasHeaders.php                 # Case-insensitive header trait
│   ├── Request.php                    # PSR-7 outgoing request
│   ├── Response.php                   # PSR-7 response
│   ├── ServerRequest.php              # PSR-7 server request + fromGlobals
│   ├── Stream.php                     # PSR-7 stream wrapper
│   ├── UploadedFile.php               # PSR-7 uploaded file
│   └── Uri.php                        # RFC 3986 URI (readonly)
└── Middleware/
    └── TrailingSlashMiddleware.php     # Normalizes trailing slashes

tests/                                  # 1118 lines, 74 test methods
├── Unit/
│   ├── Emitter/
│   │   └── SapiEmitterTest.php        # 5 tests
│   ├── Factory/
│   │   └── HttpFactoryTest.php        # 11 tests
│   ├── Handler/
│   │   └── MiddlewareDispatcherTest.php # 6 tests
│   ├── Integration/
│   │   ├── HttpNormalizerTest.php     # 6 tests
│   │   └── RouterRequestHandlerTest.php # 9 tests
│   ├── Message/
│   │   └── MessageTest.php            # 32 tests
│   └── Middleware/
│       └── TrailingSlashMiddlewareTest.php # 5 tests
```

## Dependency Direction (OCP compliant)

```
kariricode/http  ──depends-on──►  psr/http-message
kariricode/http  ──depends-on──►  psr/http-server-handler
kariricode/http  ──depends-on──►  psr/http-factory
kariricode/http  ──depends-on──►  kariricode/router (contract only)
```

HttpNormalizer lives HERE (not in Router) → Router never depends on Http.
