<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

final class ServerRequestTest extends TestCase
{
    private function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $serverParams = [],
    ): ServerRequest {
        return new ServerRequest($method, new Uri($uri), serverParams: $serverParams);
    }

    // ── Constructor ──────────────────────────────────────────────────────

    public function testConstructorDefaults(): void
    {
        $request = $this->createRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getUri()->getPath());
        self::assertSame([], $request->getServerParams());
        self::assertSame([], $request->getCookieParams());
        self::assertSame([], $request->getQueryParams());
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull($request->getParsedBody());
        self::assertSame([], $request->getAttributes());
    }

    // ── Method ───────────────────────────────────────────────────────────

    public function testWithMethodReturnsNewInstance(): void
    {
        $request = $this->createRequest('GET');
        $new = $request->withMethod('POST');

        self::assertSame('GET', $request->getMethod());
        self::assertSame('POST', $new->getMethod());
        self::assertNotSame($request, $new);
    }

    // ── URI ──────────────────────────────────────────────────────────────

    public function testWithUriReturnsNewInstance(): void
    {
        $request = $this->createRequest('GET', '/old');
        $newUri = new Uri('http://example.com/new');
        $new = $request->withUri($newUri);

        self::assertSame('/old', $request->getUri()->getPath());
        self::assertSame('/new', $new->getUri()->getPath());
    }

    public function testWithUriPreserveHost(): void
    {
        $request = $this->createRequest('GET', 'http://original.com/path');
        $newUri = new Uri('http://replacement.com/other');
        $new = $request->withUri($newUri, true);

        self::assertSame('original.com', $new->getHeaderLine('Host'));
    }

    // ── Cookie Params ────────────────────────────────────────────────────

    public function testWithCookieParams(): void
    {
        $request = $this->createRequest();
        $new = $request->withCookieParams(['session' => 'abc123']);

        self::assertSame([], $request->getCookieParams());
        self::assertSame(['session' => 'abc123'], $new->getCookieParams());
    }

    // ── Query Params ─────────────────────────────────────────────────────

    public function testWithQueryParams(): void
    {
        $request = $this->createRequest();
        $new = $request->withQueryParams(['page' => '2', 'sort' => 'name']);

        self::assertSame([], $request->getQueryParams());
        self::assertSame(['page' => '2', 'sort' => 'name'], $new->getQueryParams());
    }

    // ── Uploaded Files ───────────────────────────────────────────────────

    public function testWithUploadedFiles(): void
    {
        $request = $this->createRequest();
        $files = ['avatar' => $this->createMock(\Psr\Http\Message\UploadedFileInterface::class)];
        $new = $request->withUploadedFiles($files);

        self::assertSame([], $request->getUploadedFiles());
        self::assertCount(1, $new->getUploadedFiles());
    }

    // ── Parsed Body ──────────────────────────────────────────────────────

    public function testWithParsedBody(): void
    {
        $request = $this->createRequest();
        $body = ['name' => 'Alice', 'email' => 'alice@example.com'];
        $new = $request->withParsedBody($body);

        self::assertNull($request->getParsedBody());
        self::assertSame($body, $new->getParsedBody());
    }

    public function testWithParsedBodyNull(): void
    {
        $request = $this->createRequest();
        $new = $request->withParsedBody(null);

        self::assertNull($new->getParsedBody());
    }

    // ── Attributes ───────────────────────────────────────────────────────

    public function testWithAttribute(): void
    {
        $request = $this->createRequest();
        $new = $request->withAttribute('user_id', 42);

        self::assertSame(42, $new->getAttribute('user_id'));
        self::assertNull($request->getAttribute('user_id'));
    }

    public function testGetAttributeDefault(): void
    {
        $request = $this->createRequest();

        self::assertSame('fallback', $request->getAttribute('missing', 'fallback'));
    }

    public function testWithoutAttribute(): void
    {
        $request = $this->createRequest();
        $with = $request->withAttribute('role', 'admin');
        $without = $with->withoutAttribute('role');

        self::assertSame('admin', $with->getAttribute('role'));
        self::assertNull($without->getAttribute('role'));
    }

    public function testGetAttributes(): void
    {
        $request = $this->createRequest()
            ->withAttribute('a', 1)
            ->withAttribute('b', 2);

        self::assertSame(['a' => 1, 'b' => 2], $request->getAttributes());
    }

    // ── Headers (inherited) ──────────────────────────────────────────────

    public function testWithHeaderCaseInsensitive(): void
    {
        $request = $this->createRequest();
        $new = $request->withHeader('Content-Type', 'application/json');

        self::assertSame('application/json', $new->getHeaderLine('content-type'));
    }

    public function testWithAddedHeader(): void
    {
        $request = $this->createRequest()
            ->withHeader('Accept', 'text/html')
            ->withAddedHeader('Accept', 'application/json');

        self::assertSame('text/html, application/json', $request->getHeaderLine('Accept'));
    }

    public function testWithoutHeader(): void
    {
        $request = $this->createRequest()
            ->withHeader('X-Custom', 'value');
        $without = $request->withoutHeader('X-Custom');

        self::assertFalse($without->hasHeader('X-Custom'));
    }

    // ── Protocol Version ─────────────────────────────────────────────────

    public function testWithProtocolVersion(): void
    {
        $request = $this->createRequest();
        $new = $request->withProtocolVersion('2.0');

        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('2.0', $new->getProtocolVersion());
    }

    // ── Request Target ───────────────────────────────────────────────────

    public function testGetRequestTargetDefault(): void
    {
        $request = $this->createRequest('GET', '/api/users?page=1');
        self::assertSame('/api/users?page=1', $request->getRequestTarget());
    }

    public function testWithRequestTarget(): void
    {
        $request = $this->createRequest();
        $new = $request->withRequestTarget('*');

        self::assertSame('*', $new->getRequestTarget());
    }

    // ── Server Params ────────────────────────────────────────────────────

    public function testServerParamsFromConstructor(): void
    {
        $params = ['SERVER_NAME' => 'example.com', 'SERVER_PORT' => '443'];
        $request = $this->createRequest(serverParams: $params);

        self::assertSame($params, $request->getServerParams());
    }
}
