<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

final class UriTest extends TestCase
{
    // ── Parsing ──────────────────────────────────────────────────────────

    public function testParseFullUri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8443/path?query=1#frag');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('query=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
    }

    public function testEmptyUri(): void
    {
        $uri = new Uri('');

        self::assertSame('', $uri->getScheme());
        self::assertSame('', $uri->getHost());
        self::assertNull($uri->getPort());
        self::assertSame('', $uri->getPath());
        self::assertSame('', $uri->getQuery());
        self::assertSame('', $uri->getFragment());
    }

    public function testInvalidUriThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http:///invalid');
    }

    // ── RFC 3986 §6.2.2 — Normalization ──────────────────────────────────

    public function testSchemeIsLowercased(): void
    {
        $uri = new Uri('HTTPS://example.com');
        self::assertSame('https', $uri->getScheme());
    }

    public function testHostIsLowercased(): void
    {
        $uri = new Uri('http://EXAMPLE.COM');
        self::assertSame('example.com', $uri->getHost());
    }

    public function testDefaultPortIsOmitted(): void
    {
        $uri = new Uri('http://example.com:80');
        self::assertNull($uri->getPort());
        self::assertSame('example.com', $uri->getAuthority());
    }

    public function testNonDefaultPortIsPreserved(): void
    {
        $uri = new Uri('http://example.com:8080');
        self::assertSame(8080, $uri->getPort());
    }

    // ── Authority ────────────────────────────────────────────────────────

    public function testAuthorityWithUserInfo(): void
    {
        $uri = new Uri('http://user:pass@host.com');
        self::assertSame('user:pass@host.com', $uri->getAuthority());
    }

    public function testAuthorityWithPort(): void
    {
        $uri = new Uri('http://host.com:9090');
        self::assertSame('host.com:9090', $uri->getAuthority());
    }

    public function testEmptyAuthority(): void
    {
        $uri = new Uri('/relative/path');
        self::assertSame('', $uri->getAuthority());
    }

    // ── Immutability (with* methods) ─────────────────────────────────────

    public function testWithSchemeReturnsNewInstance(): void
    {
        $uri = new Uri('http://example.com');
        $new = $uri->withScheme('https');

        self::assertSame('http', $uri->getScheme());
        self::assertSame('https', $new->getScheme());
        self::assertNotSame($uri, $new);
    }

    public function testWithHostReturnsNewInstance(): void
    {
        $uri = new Uri('http://old.com');
        $new = $uri->withHost('new.com');

        self::assertSame('old.com', $uri->getHost());
        self::assertSame('new.com', $new->getHost());
    }

    public function testWithPortReturnsNewInstance(): void
    {
        $uri = new Uri('http://example.com');
        $new = $uri->withPort(8080);

        self::assertNull($uri->getPort());
        self::assertSame(8080, $new->getPort());
    }

    public function testWithPortNull(): void
    {
        $uri = new Uri('http://example.com:9090');
        $new = $uri->withPort(null);

        self::assertNull($new->getPort());
    }

    public function testWithPath(): void
    {
        $uri = new Uri('http://example.com/old');
        $new = $uri->withPath('/new');

        self::assertSame('/old', $uri->getPath());
        self::assertSame('/new', $new->getPath());
    }

    public function testWithQuery(): void
    {
        $uri = new Uri('http://example.com?old=1');
        $new = $uri->withQuery('new=2');

        self::assertSame('old=1', $uri->getQuery());
        self::assertSame('new=2', $new->getQuery());
    }

    public function testWithFragment(): void
    {
        $uri = new Uri('http://example.com#old');
        $new = $uri->withFragment('new');

        self::assertSame('old', $uri->getFragment());
        self::assertSame('new', $new->getFragment());
    }

    public function testWithUserInfo(): void
    {
        $uri = new Uri('http://example.com');
        $new = $uri->withUserInfo('alice', 'secret');

        self::assertSame('', $uri->getUserInfo());
        self::assertSame('alice:secret', $new->getUserInfo());
    }

    // ── __toString ───────────────────────────────────────────────────────

    public function testToStringFullUri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8443/path?q=1#f');
        self::assertSame('https://user:pass@example.com:8443/path?q=1#f', (string) $uri);
    }

    public function testToStringRelativePath(): void
    {
        $uri = new Uri('/path/to/resource');
        self::assertSame('/path/to/resource', (string) $uri);
    }

    public function testToStringQueryOnly(): void
    {
        $uri = new Uri('http://example.com?key=val');
        self::assertSame('http://example.com?key=val', (string) $uri);
    }

    public function testRoundTripPreservesUri(): void
    {
        $original = 'https://example.com:8080/api/v1?page=2&sort=name#results';
        $uri = new Uri($original);
        self::assertSame($original, (string) $uri);
    }

    // ── Edge Cases ───────────────────────────────────────────────────────

    public function testPathOnlyUri(): void
    {
        $uri = new Uri('/');
        self::assertSame('/', $uri->getPath());
        self::assertSame('/', (string) $uri);
    }

    public function testSchemeRemoval(): void
    {
        $uri = new Uri('https://example.com');
        $new = $uri->withScheme('');
        self::assertSame('', $new->getScheme());
    }
}
