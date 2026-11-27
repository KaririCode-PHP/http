<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Middleware\CorsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorsConfiguration::class)]
final class CorsConfigurationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new CorsConfiguration();

        self::assertSame(['*'], $config->allowedOrigins);
        self::assertContains('GET', $config->allowedMethods);
        self::assertContains('POST', $config->allowedMethods);
        self::assertContains('Content-Type', $config->allowedHeaders);
        self::assertSame([], $config->exposedHeaders);
        self::assertSame(86400, $config->maxAge);
        self::assertFalse($config->allowCredentials);
    }

    public function testPermissiveFactory(): void
    {
        $config = CorsConfiguration::permissive();

        self::assertTrue($config->isWildcardOrigin());
        self::assertTrue($config->isWildcardHeaders());
        self::assertContains('HEAD', $config->allowedMethods);
        self::assertFalse($config->allowCredentials);
    }

    public function testSingleOriginFactory(): void
    {
        $config = CorsConfiguration::singleOrigin('https://app.example.com');

        self::assertSame(['https://app.example.com'], $config->allowedOrigins);
        self::assertTrue($config->allowCredentials);
        self::assertFalse($config->isWildcardOrigin());
    }

    public function testIsOriginAllowedWithWildcard(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['*']);

        self::assertTrue($config->isOriginAllowed('https://anything.example.com'));
    }

    public function testIsOriginAllowedWithExplicitList(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://a.com', 'https://b.com']);

        self::assertTrue($config->isOriginAllowed('https://a.com'));
        self::assertTrue($config->isOriginAllowed('https://b.com'));
        self::assertFalse($config->isOriginAllowed('https://c.com'));
    }

    public function testIsMethodAllowedIsCaseInsensitive(): void
    {
        $config = new CorsConfiguration(allowedMethods: ['GET', 'POST']);

        self::assertTrue($config->isMethodAllowed('GET'));
        self::assertTrue($config->isMethodAllowed('get'));
        self::assertFalse($config->isMethodAllowed('DELETE'));
    }

    public function testWithAllowedOriginsReturnsNewInstance(): void
    {
        $original = new CorsConfiguration();
        $modified = $original->withAllowedOrigins(['https://new.com']);

        self::assertNotSame($original, $modified);
        self::assertSame(['*'], $original->allowedOrigins);
        self::assertSame(['https://new.com'], $modified->allowedOrigins);
    }

    public function testWithAllowedMethodsReturnsNewInstance(): void
    {
        $original = new CorsConfiguration();
        $modified = $original->withAllowedMethods(['GET']);

        self::assertNotSame($original, $modified);
        self::assertSame(['GET'], $modified->allowedMethods);
    }

    public function testWithAllowedHeadersReturnsNewInstance(): void
    {
        $original = new CorsConfiguration();
        $modified = $original->withAllowedHeaders(['X-Custom']);

        self::assertSame(['X-Custom'], $modified->allowedHeaders);
    }

    public function testWithExposedHeadersReturnsNewInstance(): void
    {
        $original = new CorsConfiguration();
        $modified = $original->withExposedHeaders(['X-Request-Id']);

        self::assertSame([], $original->exposedHeaders);
        self::assertSame(['X-Request-Id'], $modified->exposedHeaders);
    }

    public function testWithMaxAgeReturnsNewInstance(): void
    {
        $original = new CorsConfiguration();
        $modified = $original->withMaxAge(3600);

        self::assertSame(86400, $original->maxAge);
        self::assertSame(3600, $modified->maxAge);
    }

    public function testWithCredentialsReturnsNewInstance(): void
    {
        $original = new CorsConfiguration(allowedOrigins: ['https://app.example.com']);
        $modified = $original->withCredentials(true);

        self::assertFalse($original->allowCredentials);
        self::assertTrue($modified->allowCredentials);
        self::assertFalse($modified->withCredentials(false)->allowCredentials);
    }

    public function testWitherChaining(): void
    {
        $config = (new CorsConfiguration())
            ->withAllowedOrigins(['https://app.com'])
            ->withAllowedMethods(['GET', 'POST'])
            ->withCredentials(true)
            ->withMaxAge(7200);

        self::assertSame(['https://app.com'], $config->allowedOrigins);
        self::assertSame(['GET', 'POST'], $config->allowedMethods);
        self::assertTrue($config->allowCredentials);
        self::assertSame(7200, $config->maxAge);
    }

    // ── Validation ───────────────────────────────────────────────────────

    #[Test]
    public function credentialsWithAWildcardOriginAreRefused(): void
    {
        foreach ([static fn(): CorsConfiguration => new CorsConfiguration(allowCredentials: true), static fn(): CorsConfiguration => (new CorsConfiguration())->withCredentials()] as $build) {
            try {
                $build();
                self::fail('"*" with credentials was accepted');
            } catch (InvalidConfigurationException $e) {
                self::assertSame(HttpCode::CorsWildcardWithCredentials, $e->errorCode);
            }
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidOrigins(): iterable
    {
        yield 'with a path' => [['https://app.example.com/']];
        yield 'opaque null origin' => [['null']];
        yield 'subdomain pattern' => [['https://*.example.com']];
        yield 'no scheme' => [['app.example.com']];
        yield 'wildcard beside an origin' => [['*', 'https://app.example.com']];
        yield 'with user info' => [['https://user@app.example.com']];
        yield 'empty' => [['']];
    }

    /**
     * @param list<string> $origins
     */
    #[Test]
    #[DataProvider('invalidOrigins')]
    public function originsMustBeSerialisedOrigins(array $origins): void
    {
        try {
            new CorsConfiguration(allowedOrigins: $origins);
            self::fail('an invalid origin list was accepted');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(HttpCode::InvalidCorsOrigin, $e->errorCode);
        }
    }

    #[Test]
    public function validOriginsIncludePortsAndIpLiterals(): void
    {
        $origins = ['https://app.example.com', 'http://localhost:8080', 'http://[::1]:3000', 'app-scheme://device'];

        self::assertSame($origins, (new CorsConfiguration(allowedOrigins: $origins))->allowedOrigins);
        self::assertSame([], (new CorsConfiguration(allowedOrigins: []))->allowedOrigins, 'an empty list allows nobody');
        self::assertFalse((new CorsConfiguration(allowedOrigins: []))->isOriginAllowed('https://app.example.com'));
    }

    #[Test]
    public function aNegativeMaxAgeIsRefused(): void
    {
        self::assertSame(0, (new CorsConfiguration())->withMaxAge(0)->maxAge);

        try {
            (new CorsConfiguration())->withMaxAge(-1);
            self::fail('a negative max-age was accepted');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(HttpCode::NegativeMaxAge, $e->errorCode);
        }
    }

    #[Test]
    public function originsCompareWithoutRegardToCase(): void
    {
        $config = new CorsConfiguration(allowedOrigins: ['https://App.Example.com']);

        self::assertTrue($config->isOriginAllowed('https://app.example.com'));
        self::assertFalse($config->isOriginAllowed('https://app.example.com.evil.test'));
        self::assertFalse($config->isOriginAllowed('https://app.example.co'));
        self::assertFalse($config->isWildcardOrigin());
    }

    #[Test]
    public function theWildcardHeaderPolicyIsRecognised(): void
    {
        self::assertTrue(CorsConfiguration::permissive()->isWildcardHeaders());
        self::assertFalse((new CorsConfiguration())->isWildcardHeaders());
        self::assertFalse((new CorsConfiguration())->withAllowedHeaders(['*', 'X-A'])->isWildcardHeaders());
    }

    #[Test]
    public function thePermissivePolicyAllowsEveryCommonMethod(): void
    {
        self::assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], CorsConfiguration::permissive()->allowedMethods);
    }

    #[Test]
    public function configuredMethodsCompareWithoutRegardToCase(): void
    {
        $config = new CorsConfiguration(allowedMethods: ['get', 'Post']);

        self::assertTrue($config->isMethodAllowed('GET'));
        self::assertTrue($config->isMethodAllowed('post'));
        self::assertFalse($config->isMethodAllowed('PUT'));
    }
}
