<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\Uri;
use KaririCode\Http\Message\Request;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Enum\StatusCode;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    // ── Stream ───────────────────────────────────────────────────────────

    public function testStreamFromString(): void
    {
        $stream = Stream::fromString('Hello World');

        self::assertSame('Hello World', (string) $stream);
        self::assertSame(11, $stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
    }

    public function testStreamEmpty(): void
    {
        $stream = Stream::empty();

        self::assertSame('', (string) $stream);
    }

    public function testStreamReadWrite(): void
    {
        $stream = Stream::empty();
        $stream->write('foo');
        $stream->write('bar');
        $stream->rewind();

        self::assertSame('foobar', $stream->getContents());
    }

    public function testStreamDetachInvalidatesOperations(): void
    {
        $stream = Stream::fromString('data');
        $resource = $stream->detach();

        self::assertIsResource($resource);
        self::assertSame('', (string) $stream);

        $this->expectException(\RuntimeException::class);
        $stream->read(1);
    }

    public function testStreamTellAndSeek(): void
    {
        $stream = Stream::fromString('abcdef');
        self::assertSame(0, $stream->tell());

        $stream->seek(3);
        self::assertSame(3, $stream->tell());
        self::assertSame('def', $stream->getContents());
    }

    // ── Uri ──────────────────────────────────────────────────────────────

    public function testUriParsing(): void
    {
        $uri = new Uri('https://user:pass@example.com:8080/path?query=1#frag');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('query=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
        self::assertSame('user:pass@example.com:8080', $uri->getAuthority());
    }

    public function testUriDefaultPortOmitted(): void
    {
        $uri = new Uri('https://example.com:443/path');

        self::assertNull($uri->getPort());
        self::assertSame('example.com', $uri->getAuthority());
    }

    public function testUriImmutability(): void
    {
        $original = new Uri('https://example.com/path');
        $modified = $original->withHost('other.com');

        self::assertSame('example.com', $original->getHost());
        self::assertSame('other.com', $modified->getHost());
        self::assertNotSame($original, $modified);
    }

    public function testUriToString(): void
    {
        $uri = new Uri('https://example.com:8080/path?q=1#f');

        self::assertSame('https://example.com:8080/path?q=1#f', (string) $uri);
    }

    public function testUriEmptyConstructor(): void
    {
        $uri = new Uri();

        self::assertSame('', (string) $uri);
        self::assertSame('', $uri->getScheme());
        self::assertSame('', $uri->getHost());
    }

    public function testUriWithMethods(): void
    {
        $uri = (new Uri('http://example.com'))
            ->withScheme('https')
            ->withUserInfo('admin', 'secret')
            ->withHost('other.com')
            ->withPort(9090)
            ->withPath('/api/v2')
            ->withQuery('page=1')
            ->withFragment('top');

        self::assertSame('https://admin:secret@other.com:9090/api/v2?page=1#top', (string) $uri);
    }

    // ── Request ──────────────────────────────────────────────────────────

    public function testRequestDefaults(): void
    {
        $request = new Request();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getRequestTarget());
        self::assertSame('1.1', $request->getProtocolVersion());
    }

    public function testRequestWithMethodAndUri(): void
    {
        $request = new Request('POST', 'https://api.example.com/users?page=1');

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/users?page=1', $request->getRequestTarget());
        self::assertSame('api.example.com', $request->getUri()->getHost());
        self::assertTrue($request->hasHeader('Host'));
        self::assertSame('api.example.com', $request->getHeaderLine('Host'));
    }

    public function testRequestImmutability(): void
    {
        $original = new Request('GET', '/users');
        $modified = $original->withMethod('POST');

        self::assertSame('GET', $original->getMethod());
        self::assertSame('POST', $modified->getMethod());
    }

    public function testRequestHeaders(): void
    {
        $request = (new Request('GET', '/'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/html')
            ->withAddedHeader('Accept', 'application/json');

        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('text/html, application/json', $request->getHeaderLine('Accept'));
        self::assertTrue($request->hasHeader('content-type')); // case-insensitive
    }

    public function testRequestBody(): void
    {
        $body = Stream::fromString('{"name":"test"}');
        $request = (new Request('POST', '/'))->withBody($body);

        self::assertSame('{"name":"test"}', (string) $request->getBody());
    }

    // ── Response ─────────────────────────────────────────────────────────

    public function testResponseDefaults(): void
    {
        $response = new Response();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
    }

    public function testResponseWithStatus(): void
    {
        $response = new Response(404);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testResponseCustomReasonPhrase(): void
    {
        $response = new Response(200, 'All Good');

        self::assertSame('All Good', $response->getReasonPhrase());
    }

    public function testResponseImmutability(): void
    {
        $original = new Response(200);
        $modified = $original->withStatus(301);

        self::assertSame(200, $original->getStatusCode());
        self::assertSame(301, $modified->getStatusCode());
        self::assertSame('Moved Permanently', $modified->getReasonPhrase());
    }

    public function testResponseAutoReasonFromEnum(): void
    {
        $response = new Response(418);

        self::assertSame("I'm a teapot", $response->getReasonPhrase());
    }

    // ── ServerRequest ────────────────────────────────────────────────────

    public function testServerRequestDefaults(): void
    {
        $request = new ServerRequest('GET', '/users');

        self::assertSame('GET', $request->getMethod());
        self::assertSame([], $request->getQueryParams());
        self::assertSame([], $request->getCookieParams());
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull($request->getParsedBody());
        self::assertSame([], $request->getAttributes());
    }

    public function testServerRequestAttributes(): void
    {
        $request = (new ServerRequest('GET', '/users/42'))
            ->withAttribute('id', '42')
            ->withAttribute('_route', 'user.show');

        self::assertSame('42', $request->getAttribute('id'));
        self::assertSame('user.show', $request->getAttribute('_route'));
        self::assertNull($request->getAttribute('missing'));
        self::assertSame('default', $request->getAttribute('missing', 'default'));
    }

    public function testServerRequestWithoutAttribute(): void
    {
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('foo', 'bar')
            ->withAttribute('baz', 'qux');

        $modified = $request->withoutAttribute('foo');

        self::assertNull($modified->getAttribute('foo'));
        self::assertSame('qux', $modified->getAttribute('baz'));
        self::assertSame('bar', $request->getAttribute('foo')); // original unchanged
    }

    public function testServerRequestQueryAndCookieParams(): void
    {
        $request = (new ServerRequest('GET', '/search'))
            ->withQueryParams(['q' => 'php', 'page' => '1'])
            ->withCookieParams(['session' => 'abc123']);

        self::assertSame(['q' => 'php', 'page' => '1'], $request->getQueryParams());
        self::assertSame(['session' => 'abc123'], $request->getCookieParams());
    }

    public function testServerRequestParsedBody(): void
    {
        $request = (new ServerRequest('POST', '/users'))
            ->withParsedBody(['name' => 'Walmir', 'email' => 'w@k.org']);

        self::assertSame(['name' => 'Walmir', 'email' => 'w@k.org'], $request->getParsedBody());
    }

    // ── StatusCode Enum ──────────────────────────────────────────────────

    public function testStatusCodePureEnum(): void
    {
        self::assertSame(200, StatusCode::Ok->code());
        self::assertSame('OK', StatusCode::Ok->reasonPhrase());
        self::assertTrue(StatusCode::Ok->isSuccess());
        self::assertFalse(StatusCode::Ok->isClientError());
    }

    public function testStatusCodeCategories(): void
    {
        self::assertTrue(StatusCode::Continue->isInformational());
        self::assertTrue(StatusCode::Created->isSuccess());
        self::assertTrue(StatusCode::MovedPermanently->isRedirection());
        self::assertTrue(StatusCode::NotFound->isClientError());
        self::assertTrue(StatusCode::InternalServerError->isServerError());
    }

    public function testStatusCodeTryFromCode(): void
    {
        self::assertSame(StatusCode::NotFound, StatusCode::tryFromCode(404));
        self::assertNull(StatusCode::tryFromCode(999));
    }
}
