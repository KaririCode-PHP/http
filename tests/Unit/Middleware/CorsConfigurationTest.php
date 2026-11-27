<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Middleware\CorsConfiguration;
use PHPUnit\Framework\TestCase;

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
        $original = new CorsConfiguration();
        $modified = $original->withCredentials(true);

        self::assertFalse($original->allowCredentials);
        self::assertTrue($modified->allowCredentials);
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
}
