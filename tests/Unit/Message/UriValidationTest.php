<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidUriException;
use KaririCode\Http\Message\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uri::class)]
#[CoversClass(InvalidUriException::class)]
final class UriValidationTest extends TestCase
{
    // ── Host ─────────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostsThatMoveTheAuthority(): iterable
    {
        yield 'user info' => ['good.example@evil.test'];
        yield 'path' => ['evil.test/path'];
        yield 'query' => ['evil.test?x'];
        yield 'fragment' => ['evil.test#x'];
        yield 'port' => ['evil.test:80'];
        yield 'space' => ['evil test'];
        yield 'line feed' => ["evil.test\n"];
        yield 'unbalanced bracket' => ['[::1'];
        yield 'bracketed garbage' => ['[not-ip]'];
    }

    #[Test]
    #[DataProvider('hostsThatMoveTheAuthority')]
    public function aHostIsARegisteredNameOrAnIpLiteral(string $host): void
    {
        self::assertInvalid(HttpCode::InvalidHost, static fn() => (new Uri())->withHost($host));
    }

    #[Test]
    public function validHostsAreLowerCased(): void
    {
        self::assertSame('example.com', (new Uri())->withHost('EXAMPLE.com')->getHost());
        self::assertSame('[2001:db8::1]', (new Uri())->withHost('[2001:DB8::1]')->getHost());
        self::assertSame('[v1.fe80::a+en1]', (new Uri())->withHost('[v1.fe80::a+en1]')->getHost(), 'IPvFuture');
        self::assertSame('caf%c3%a9.example', (new Uri())->withHost('caf%C3%A9.example')->getHost());
        self::assertSame('', (new Uri('https://example.com'))->withHost('')->getHost());
    }

    // ── Scheme and port ──────────────────────────────────────────────────

    #[Test]
    public function aSchemeFollowsRfc3986(): void
    {
        foreach (['ht tp', '1http', 'http:', "http\n", 'ht/tp'] as $scheme) {
            self::assertInvalid(HttpCode::InvalidScheme, static fn() => (new Uri())->withScheme($scheme));
        }

        self::assertSame('svn+ssh', (new Uri())->withScheme('SVN+SSH')->getScheme());
        self::assertSame('', (new Uri('https://a.example'))->withScheme('')->getScheme());
    }

    #[Test]
    public function aPortIsBetween0And65535(): void
    {
        foreach ([-1, 65536] as $port) {
            self::assertInvalid(HttpCode::InvalidPort, static fn() => (new Uri())->withPort($port));
        }

        self::assertSame(65535, (new Uri())->withPort(65535)->getPort());
        self::assertSame(0, (new Uri())->withPort(0)->getPort());
    }

    #[Test]
    public function aDefaultPortIsHiddenForItsSchemeOnly(): void
    {
        self::assertNull((new Uri('wss://a.example:443'))->getPort());
        self::assertNull((new Uri('ws://a.example:80'))->getPort());
        self::assertSame(443, (new Uri('http://a.example:443'))->getPort());
        self::assertSame('http://a.example:443', (string) new Uri('http://a.example:443'));
        self::assertSame(8080, (new Uri('a.example:8080'))->withScheme('')->withHost('a.example')->getPort(), 'no scheme, no default');
    }

    #[Test]
    public function anUnparsableUriIsRefused(): void
    {
        foreach (['http://:80', 'http:///example.com', '//a.example:99999'] as $uri) {
            self::assertInvalid(HttpCode::UnparsableUri, static fn() => new Uri($uri));
        }
    }

    // ── Percent-encoding ─────────────────────────────────────────────────

    #[Test]
    public function charactersOutsideAComponentAreEncodedOnce(): void
    {
        $uri = (new Uri())
            ->withPath('/a b/ç/%2F/100%')
            ->withQuery('q=a b&x=%41&y=[1]')
            ->withFragment('sec tion#2');

        self::assertSame('/a%20b/%C3%A7/%2F/100%25', $uri->getPath());
        self::assertSame('q=a%20b&x=%41&y=%5B1%5D', $uri->getQuery());
        self::assertSame('sec%20tion%232', $uri->getFragment());
        self::assertSame('/a%20b/%C3%A7/%2F/100%25', (new Uri())->withPath($uri->getPath())->getPath(), 'encoding is idempotent');
    }

    #[Test]
    public function userInfoIsEncodedAndTheColonSeparatesThePassword(): void
    {
        $uri = (new Uri('https://a.example'))->withUserInfo('us:er@x', 'p@ss:w rd');

        self::assertSame('us%3Aer%40x:p%40ss:w%20rd', $uri->getUserInfo());
        self::assertSame('https://us%3Aer%40x:p%40ss:w%20rd@a.example', (string) $uri);
        self::assertSame('user', (new Uri())->withUserInfo('user', '')->getUserInfo(), 'an empty password is no password');
        self::assertSame('user', (new Uri())->withUserInfo('user')->getUserInfo());
        self::assertSame('', (new Uri('https://u:p@a.example'))->withUserInfo('')->getUserInfo());
    }

    #[Test]
    public function aParsedUriIsEncodedTheSameWay(): void
    {
        $uri = new Uri('https://u%20x:p@Example.COM:8443/a b?c=d e#f g');

        self::assertSame('u%20x:p', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame('/a%20b', $uri->getPath());
        self::assertSame('c=d%20e', $uri->getQuery());
        self::assertSame('f%20g', $uri->getFragment());
        self::assertSame('u%20x:p@example.com:8443', $uri->getAuthority());
    }

    // ── String form ──────────────────────────────────────────────────────

    #[Test]
    public function thePathCannotBeReadAsAnAuthority(): void
    {
        self::assertSame('https://a.example/rootless', (string) (new Uri('https://a.example'))->withPath('rootless'));
        self::assertSame('/evil.test/x', (string) (new Uri())->withPath('//evil.test/x'));
        self::assertSame('https:/evil.test', (string) (new Uri())->withScheme('https')->withPath('///evil.test'));
        self::assertSame('https://a.example//double', (string) (new Uri('https://a.example'))->withPath('//double'), 'with an authority, slashes stay');
    }

    #[Test]
    public function withersReturnACopyAndKeepTheOtherComponents(): void
    {
        $uri = new Uri('https://u@a.example:8443/p?q#f');
        $changed = $uri->withPath('/other');

        self::assertNotSame($uri, $changed);
        self::assertSame('/p', $uri->getPath());
        self::assertSame('https://u@a.example:8443/other?q#f', (string) $changed);
        self::assertSame('https://u@a.example:8443/p?q', (string) $uri->withFragment(''));
        self::assertSame('https://u@a.example:8443/p#f', (string) $uri->withQuery(''));
        self::assertSame('https://u@a.example/p?q#f', (string) $uri->withPort(null));
        self::assertSame('', (new Uri('https://u@a.example'))->withHost('')->getAuthority(), 'no host, no authority');
    }

    private static function assertInvalid(HttpCode $code, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('the component was accepted');
        } catch (InvalidUriException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }
}
