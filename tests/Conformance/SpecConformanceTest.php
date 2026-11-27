<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Conformance;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\ContainerFailure;
use KaririCode\Contract\Exception\ExceptionInterface;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\CarriesErrorCode;
use KaririCode\Exception\ConfigurationException;
use KaririCode\Exception\ErrorCode;
use KaririCode\Exception\RuntimeException;
use KaririCode\Exception\ValidationException;
use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\InvalidUriException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * KaririCode Spec V4.3.3 conformance of src/ (§29.2): every rule runs over
 * every type. The accepted deviations are listed here and in the quality
 * report (docs/quality/QD-V433-COMPLIANCE.md §3), and the rules fail when
 * the list and the code drift apart.
 */
#[CoversNothing]
final class SpecConformanceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    private const string ROOT = __DIR__ . '/../..';

    private const array FAMILIES = [
        Configuration::class,
        ContainerFailure::class,
        NotFound::class,
        Protocol::class,
        Runtime::class,
        Validation::class,
    ];

    private const array FAMILY_BASES = [
        ConfigurationException::class,
        RuntimeException::class,
        ValidationException::class,
    ];

    /**
     * Accepted deviation (ADR-003): PSR-7 asks for \InvalidArgumentException,
     * which ValidationException (a \DomainException) is not, so these two
     * extend it and carry their code through CarriesErrorCode.
     */
    private const array PSR7_ARGUMENT_EXCEPTIONS = [
        InvalidMessageException::class,
        InvalidUriException::class,
    ];

    /** Accepted deviation: the signatures of kariricode/router's RequestNormalizer (its ADR-002). */
    private const array OBJECT_PARAMETERS = [
        'KaririCode\Http\Integration\HttpNormalizer::canNormalize',
        'KaririCode\Http\Integration\HttpNormalizer::normalize',
    ];

    /** Accepted deviation (ADR-001): the stream owns its resource and closes it when it goes away. */
    private const array MAGIC_METHODS = [
        'KaririCode\Http\Message\Stream::__destruct',
    ];

    #[Test]
    public function eachFileDeclaresStrictTypesItsNamespaceAndOneTypeNamedAfterIt(): void
    {
        $found = [];

        foreach (self::files() as $relative => $file) {
            $source = (string) file_get_contents($file);
            $namespace = 'KaririCode\Http' . (dirname($relative) === '.' ? '' : '\\' . str_replace('/', '\\', dirname($relative)));
            $declared = preg_match_all('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $source, $names);

            if (!str_contains($source, "declare(strict_types=1);\n\nnamespace {$namespace};")) {
                $found[] = $relative . ': strict types and namespace ' . $namespace;
            }

            if ($declared !== 1 || $names[1][0] !== basename($file, '.php')) {
                $found[] = $relative . ': one type named after the file';
            }
        }

        self::assertSame([], $found);
        self::assertCount(35, self::types(), 'src/ holds the 35 types of SPEC-001 §2');
    }

    #[Test]
    public function everyClassIsFinal(): void
    {
        foreach (self::types() as $type) {
            if (!$type->isInterface() && !$type->isTrait() && !$type->isEnum()) {
                self::assertTrue($type->isFinal(), $type->getName() . ' is final');
            }
        }
    }

    #[Test]
    public function namesFollowSection16AndAvoidVagueNouns(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            $name = $type->getShortName();

            if (preg_match('/Interface$|(?:Manager|Helper|Utils?)(?=[A-Z]|$)/', $name) === 1) {
                $found[] = $name . ': forbidden suffix (§16.3, §18)';
            }

            if ($type->isSubclassOf(\Throwable::class) !== str_ends_with($name, 'Exception')) {
                $found[] = $name . ': only exceptions end in Exception (§16.3)';
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function noZeroArgumentGettersSettersSerializersOrMagicMethodsBeyondPsr(): void
    {
        $found = [];

        foreach (self::ownMethods() as $name => $method) {
            $short = $method->getName();
            $psr = in_array($short, self::psrMethods($method->getDeclaringClass()), true);

            if (!$psr && preg_match('/^get(?:[A-Z_]|$)/', $short) === 1 && $method->getNumberOfParameters() === 0) {
                $found[] = $name . ': zero-argument getter (§18)';
            }

            if (preg_match('/^set[A-Z]/', $short) === 1) {
                $found[] = $name . ': setter (§18)';
            }

            if (in_array(strtolower($short), ['toarray', 'fromarray', 'jsonserialize'], true)) {
                $found[] = $name . ': serializes itself (§18)';
            }

            if (!$psr && str_starts_with($short, '__') && $short !== '__construct' && !in_array($name, self::MAGIC_METHODS, true)) {
                $found[] = $name . ': magic method (§29.2)';
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function stateIsWriteProtectedAndNeverStatic(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            foreach ($type->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $type->getName()) {
                    continue;
                }

                if ($property->isStatic()) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': static state (§18, §29.2)';
                }

                if ($property->isPublic() && !self::isWriteProtected($property)) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': public and writable';
                }

                if ($property->isReadOnly() && $property->hasHooks()) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': hook on a readonly property (E-040)';
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function constantsAreTyped(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            foreach ($type->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $type->getName() && !$constant->isEnumCase() && !$constant->hasType()) {
                    $found[] = $type->getName() . '::' . $constant->getName();
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function inheritanceStaysWithinTwoLevelsOfUserClasses(): void
    {
        foreach (self::types() as $type) {
            $depth = 0;

            for ($parent = $type->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                $depth += $parent->isInternal() ? 0 : 1;
            }

            self::assertLessThanOrEqual(1, $depth, $type->getName() . ': a component class ends at depth 2 (§18)');
        }
    }

    #[Test]
    public function everyExceptionBelongsToOneFamilyAndExtendsAFamilyBaseOrTheDocumentedSplParent(): void
    {
        $exceptions = 0;

        foreach (self::types() as $type) {
            if (!$type->isSubclassOf(\Throwable::class)) {
                continue;
            }

            ++$exceptions;
            $parent = $type->getParentClass() === false ? null : $type->getParentClass()->getName();
            self::assertTrue($type->implementsInterface(ExceptionInterface::class), $type->getName());
            self::assertCount(1, array_intersect(self::FAMILIES, $type->getInterfaceNames()), $type->getName() . ': one family');

            if (in_array($type->getName(), self::PSR7_ARGUMENT_EXCEPTIONS, true)) {
                self::assertSame(\InvalidArgumentException::class, $parent, $type->getName() . ': PSR-7 argument error');
                self::assertContains(CarriesErrorCode::class, $type->getTraitNames(), $type->getName() . ': carries its code');
                self::assertTrue($type->implementsInterface(Validation::class), $type->getName() . ': a validation failure');
                continue;
            }

            self::assertContains($parent, self::FAMILY_BASES, $type->getName() . ': extends a kariricode/exception base');
        }

        self::assertSame(6, $exceptions);
    }

    #[Test]
    public function concreteExceptionsAreBuiltByNamedConstructorsThatUseHttpCode(): void
    {
        foreach (self::types() as $type) {
            if (!$type->isSubclassOf(\Throwable::class)) {
                continue;
            }

            $factories = 0;
            $source = (string) file_get_contents((string) $type->getFileName());

            foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $type->getName()) {
                    continue;
                }

                self::assertTrue($method->isStatic(), $type->getName() . '::' . $method->getName() . ' is a named constructor');
                // PHP 8.5 reports a self return type by the resolved class name.
                self::assertContains((string) $method->getReturnType(), ['self', $type->getName()]);
                ++$factories;
            }

            self::assertGreaterThan(0, $factories, $type->getName());
            self::assertSame(0, preg_match('/new self\(\s*+(?!HttpCode::)/', $source), $type->getName() . ': every code is an HttpCode case');
            self::assertFalse(self::interpolates($source), $type->getName() . ': no interpolated values; identifiers go through quote() (E-047)');
        }
    }

    #[Test]
    public function docblocksMeetTheFiveCriteria(): void
    {
        $markers = [
            'preconditions (C2)' => 'Preconditions',
            'thread-safety (C2)' => 'Thread-safety:',
            'side effects (C3)' => 'Side effects:',
            'example (C4)' => 'Example',
            'references (C5)' => 'References:',
            'link (C5)' => '@see https://',
        ];
        $missing = [];

        foreach (self::types() as $type) {
            $docblock = (string) $type->getDocComment();

            foreach ($markers as $criterion => $marker) {
                if (!str_contains($docblock, $marker)) {
                    $missing[] = $type->getShortName() . ': ' . $criterion;
                }
            }

            if (preg_match('/@(?:package|author|license|copyright|version)\b/', $docblock) === 1) {
                $missing[] = $type->getShortName() . ': authorship tag';
            }

            if (preg_match('/\A\/\*\*\s+\*\s+Represents\b/', $docblock) === 1) {
                $missing[] = $type->getShortName() . ': C1 states the domain role, not "Represents"';
            }
        }

        self::assertSame([], $missing);
    }

    #[Test]
    public function adrAndSpecReferencesResolve(): void
    {
        $missing = [];
        $adrs = array_map(static fn(string $file): string => substr(basename($file), 0, 7), glob(self::ROOT . '/docs/adr/ADR-*.md') ?: []);

        foreach (self::files() as $relative => $file) {
            preg_match_all('/\b(ADR-\d{3})\b/', (string) file_get_contents($file), $references);

            foreach (array_unique($references[1]) as $adr) {
                if (!in_array($adr, $adrs, true)) {
                    $missing[] = $relative . ': ' . $adr;
                }
            }

            if (str_contains((string) file_get_contents($file), 'SPEC-001') && !is_file(self::ROOT . '/docs/spec/SPEC-001-http-architecture.md')) {
                $missing[] = $relative . ': SPEC-001';
            }
        }

        self::assertSame([], $missing, 'all ADR/SPEC references intact (§29.2)');
    }

    #[Test]
    public function adrsFollowTheCanonicalSections(): void
    {
        $adrs = glob(self::ROOT . '/docs/adr/ADR-[0-9][0-9][0-9]-*.md') ?: [];
        self::assertCount(7, $adrs);

        foreach ($adrs as $adr) {
            preg_match_all('/^## (.+)$/m', (string) file_get_contents($adr), $sections);
            $order = array_values(array_intersect($sections[1], ['Context', 'Decision', 'Consequences', 'Alternatives Considered', 'References']));

            self::assertSame(['Context', 'Decision', 'Consequences', 'Alternatives Considered', 'References'], $order, basename($adr));
        }
    }

    #[Test]
    public function objectParametersAreTheAcceptedDeviationOnly(): void
    {
        $found = [];

        foreach (self::ownMethods() as $name => $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === 'object') {
                    $found[] = $name;
                }
            }
        }

        self::assertSame(self::OBJECT_PARAMETERS, array_values(array_unique($found)));
    }

    #[Test]
    public function sourceAvoidsSuppressionAndDebugConstructs(): void
    {
        $found = [];

        foreach (self::files() as $relative => $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if ($token === '@') {
                    $found[] = $relative . ': error suppression';
                }

                if (is_array($token) && in_array($token[0], [T_EVAL, T_EXIT, T_PRINT], true)) {
                    $found[] = $relative . ': ' . $token[1];
                }

                if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), ['var_dump', 'print_r', 'var_export', 'error_log'], true)) {
                    $found[] = $relative . ': ' . $token[1];
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function theRuntimeDependsOnPhpTheIntegratorPackagesTheRouterAndThePsrInterfaces(): void
    {
        /** @var array{require: array<string, string>, extra: array{kariricode: array{arfa: array{version: string, level: int}, asvs: array{version: string, level: int}, spec: string, error-code-range: string}}} $composer */
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'php' => '^8.4',
                'kariricode/exception' => '^2.0.2',
                'kariricode/router' => '^4.1',
                'psr/clock' => '^1.0',
                'psr/http-factory' => '^1.1',
                'psr/http-message' => '^2.0',
                'psr/http-server-handler' => '^1.0',
                'psr/http-server-middleware' => '^1.0',
            ],
            $composer['require'],
            'Spec V4.3.3 §31: coupling through the integrator packages and PSR interfaces; 2.0.2 is the first exception release with the HTTP block',
        );
        self::assertArrayNotHasKey('require-dev', $composer, 'the toolchain comes from devkit.php (ADR-004)');
        self::assertSame(['version' => '1.3', 'level' => 1], $composer['extra']['kariricode']['arfa'], '§29.3 G4');
        self::assertSame(['version' => '5.0', 'level' => 1], $composer['extra']['kariricode']['asvs'], '§29.3 G4');
        self::assertSame('4.3.3', $composer['extra']['kariricode']['spec'], 'E-044');
        self::assertSame('HTTP 1700–1799', $composer['extra']['kariricode']['error-code-range']);
    }

    #[Test]
    public function theRouterIsImportedOnlyByTheIntegration(): void
    {
        $found = [];
        $allowed = ['KaririCode\Http', 'KaririCode\Exception', 'KaririCode\Contract', 'Psr\Http', 'Psr\Clock'];

        foreach (self::files() as $relative => $file) {
            preg_match_all('/^use ((?:KaririCode|Psr)\\\\[A-Za-z]+)\\\\/m', (string) file_get_contents($file), $imports);

            foreach ($imports[1] as $namespace) {
                $integration = str_starts_with($relative, 'Integration/') && $namespace === 'KaririCode\Router';

                if (!$integration && !in_array($namespace, $allowed, true)) {
                    $found[] = $relative . ': ' . $namespace;
                }
            }
        }

        self::assertSame([], $found, 'messages, middleware and the emitter work without the router (§31)');
    }

    #[Test]
    public function theCodeEnumIsTheOnlyEnumThatCarriesErrorCodes(): void
    {
        $codeEnums = array_filter(self::types(), static fn(ReflectionClass $type): bool => $type->isEnum() && $type->implementsInterface(ErrorCode::class));

        self::assertSame([HttpCode::class], array_values(array_map(static fn(ReflectionClass $type): string => $type->getName(), $codeEnums)));
    }

    /**
     * @return array<string, string> path relative to src/ => absolute path
     */
    private static function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen(self::SOURCE) + 1))] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    private static function types(): array
    {
        $types = [];

        foreach (array_keys(self::files()) as $relative) {
            $name = 'KaririCode\Http\\' . str_replace('/', '\\', substr($relative, 0, -4));
            self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name), $name);

            /** @var class-string $name */
            $types[] = new ReflectionClass($name);
        }

        return $types;
    }

    /**
     * @return array<string, ReflectionMethod>
     */
    private static function ownMethods(): array
    {
        $methods = [];

        foreach (self::types() as $type) {
            foreach ($type->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $type->getName()) {
                    $methods[$type->getName() . '::' . $method->getName()] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * The methods PSR interfaces declare for $type: its own Psr\ interfaces,
     * or, for a trait, those of the classes in src/ that use it.
     *
     * @param ReflectionClass<object> $type
     *
     * @return list<string>
     */
    private static function psrMethods(ReflectionClass $type): array
    {
        $holders = $type->isTrait()
            ? array_filter(self::types(), static fn(ReflectionClass $user): bool => in_array($type->getName(), $user->getTraitNames(), true))
            : [$type];
        $methods = [];

        foreach ($holders as $holder) {
            foreach ($holder->getInterfaces() as $interface) {
                if (str_starts_with($interface->getName(), 'Psr\\')) {
                    array_push($methods, ...array_map(static fn(ReflectionMethod $method): string => $method->getName(), $interface->getMethods()));
                }
            }
        }

        return array_values(array_unique($methods));
    }

    /** True when the source builds a string by interpolating a variable. */
    private static function interpolates(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if ($token === '"' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_START_HEREDOC], true))) {
                return true;
            }
        }

        return false;
    }

    /** readonly, private(set) or protected(set): §18 accepts all three. */
    private static function isWriteProtected(ReflectionProperty $property): bool
    {
        return $property->isReadOnly() || $property->isPrivateSet() || $property->isProtectedSet();
    }
}
