<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Integration;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Integration\HttpNormalizer;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests HttpNormalizer — the bridge between PSR-7 and Router V4.1.
 *
 * Requires kariricode/router as dev dependency.
 */
#[CoversClass(HttpNormalizer::class)]
final class HttpNormalizerTest extends TestCase
{
    private HttpNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HttpNormalizer();
    }

    public function testImplementsRequestNormalizerContract(): void
    {
        self::assertInstanceOf(RequestNormalizer::class, $this->normalizer);
    }

    public function testCanNormalizeAcceptsServerRequest(): void
    {
        $request = new ServerRequest('GET', '/users');

        self::assertTrue($this->normalizer->canNormalize($request));
    }

    public function testCanNormalizeRejectsNonPsr7(): void
    {
        self::assertFalse($this->normalizer->canNormalize(new \stdClass()));
    }

    public function testNormalizeExtractsMethodAndUri(): void
    {
        $request = new ServerRequest('POST', 'https://api.example.com/users/42?page=1');

        $normalized = $this->normalizer->normalize($request);

        self::assertInstanceOf(NormalizedRequest::class, $normalized);
        self::assertSame('POST', $normalized->method);
        self::assertSame('/users/42', $normalized->uri);
        self::assertSame($request, $normalized->original);
    }

    public function testNormalizeUppercasesMethod(): void
    {
        $request = (new ServerRequest('get', '/test'));

        $normalized = $this->normalizer->normalize($request);

        self::assertSame('GET', $normalized->method);
    }

    public function testNormalizeDefaultsEmptyPathToSlash(): void
    {
        $request = new ServerRequest('GET', 'https://example.com');

        $normalized = $this->normalizer->normalize($request);

        self::assertSame('/', $normalized->uri);
    }

    public function testNormalizePreservesOriginalRequestForHandlerAccess(): void
    {
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('tenant_id', 'abc-123')
            ->withHeader('Authorization', 'Bearer token');

        $normalized = $this->normalizer->normalize($request);

        $original = $normalized->original;
        self::assertInstanceOf(ServerRequest::class, $original);
        self::assertSame('abc-123', $original->getAttribute('tenant_id'));
        self::assertSame('Bearer token', $original->getHeaderLine('Authorization'));
    }

    #[Test]
    public function normalizingSomethingElseIsAProgrammingError(): void
    {
        try {
            (new HttpNormalizer())->normalize(new \stdClass());
            self::fail('a non-PSR-7 object was normalised');
        } catch (InvalidMessageException $e) {
            self::assertSame(HttpCode::NotAServerRequest, $e->errorCode);
        }
    }
}
