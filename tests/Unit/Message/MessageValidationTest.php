<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Message\HttpGrammar;
use KaririCode\Http\Message\Request;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(ServerRequest::class)]
#[CoversClass(HttpGrammar::class)]
#[CoversClass(InvalidMessageException::class)]
final class MessageValidationTest extends TestCase
{
    // ── Header names ─────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaderNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['X Custom'];
        yield 'colon' => ['X-Custom:'];
        yield 'line feed' => ["X-Custom\nInjected"];
        yield 'carriage return' => ["X-Custom\r"];
        yield 'non-ASCII' => ['X-Ação'];
        yield 'separator' => ['X(Custom)'];
        yield 'NUL' => ["X\0"];
    }

    #[Test]
    #[DataProvider('invalidHeaderNames')]
    public function headerNamesMustBeTokens(string $name): void
    {
        $response = new Response();

        self::assertInvalid(HttpCode::InvalidHeaderName, static fn() => $response->withHeader($name, 'v'));
        self::assertInvalid(HttpCode::InvalidHeaderName, static fn() => $response->withAddedHeader($name, 'v'));
        self::assertInvalid(HttpCode::InvalidHeaderName, static fn() => new Response(headers: [$name => 'v']));
    }

    #[Test]
    public function tokenCharactersAreAllAccepted(): void
    {
        $name = "!#$%&'*+-.^_`|~09AZaz";

        self::assertSame(['v'], (new Response())->withHeader($name, 'v')->getHeader($name));
    }

    // ── Header values ────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidHeaderValues(): iterable
    {
        yield 'CRLF injection' => ["value\r\nSet-Cookie: admin=1"];
        yield 'bare line feed' => ["value\nX: y"];
        yield 'bare carriage return' => ["value\rX: y"];
        yield 'NUL' => ["value\0"];
        yield 'DEL' => ["value\x7F"];
        yield 'other control' => ["value\x01"];
        yield 'one bad item in a list' => [['ok', "bad\n"]];
        yield 'empty list' => [[]];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'nested list' => [[['nested']]];
        yield 'object' => [new \stdClass()];
    }

    #[Test]
    #[DataProvider('invalidHeaderValues')]
    public function headerValuesMustBeFieldValues(mixed $value): void
    {
        $request = new Request();

        // @phpstan-ignore argument.type (a caller without static analysis can pass anything; the runtime check is under test)
        self::assertInvalid(HttpCode::InvalidHeaderValue, static fn() => $request->withHeader('X-Test', $value));
        // @phpstan-ignore argument.type (as above)
        self::assertInvalid(HttpCode::InvalidHeaderValue, static fn() => $request->withAddedHeader('X-Test', $value));
        self::assertInvalid(HttpCode::InvalidHeaderValue, static fn() => new Request(headers: ['X-Test' => $value]));
    }

    #[Test]
    public function theRejectedValueNeverReachesTheMessage(): void
    {
        try {
            (new Response())->withHeader("X-A\u{202E}", "x\r\nSet-Cookie: admin=1");
            self::fail('accepted');
        } catch (InvalidMessageException $e) {
            self::assertSame('[HTTP_1700] A header name must be an RFC 9110 token.', $e->getMessage());
        }

        try {
            (new Response())->withHeader('X-Token', "secret\n");
            self::fail('accepted');
        } catch (InvalidMessageException $e) {
            self::assertStringContainsString('"X-Token"', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage(), 'E-047: the value may come from a client');
        }
    }

    #[Test]
    public function valuesAreTrimmedNumbersBecomeTextAndObsTextIsKept(): void
    {
        $response = (new Response())
            ->withHeader('X-Padded', " \tvalue\t ")
            ->withHeader('X-Number', 42)
            ->withHeader('X-Float', 1.5)
            ->withHeader('X-Latin1', "caf\xE9")
            ->withHeader('X-Empty', '')
            ->withHeader('X-Inner-Space', 'a  b');

        self::assertSame(['value'], $response->getHeader('X-Padded'));
        self::assertSame(['42'], $response->getHeader('X-Number'));
        self::assertSame(['1.5'], $response->getHeader('X-Float'));
        self::assertSame(["caf\xE9"], $response->getHeader('X-Latin1'));
        self::assertSame([''], $response->getHeader('X-Empty'));
        self::assertSame(['a  b'], $response->getHeader('X-Inner-Space'));
    }

    #[Test]
    public function aListKeepsItsOrderAndKeysAreDropped(): void
    {
        $response = (new Response())->withHeader('Set-Cookie', ['b' => 'one', 'a' => 'two'])->withAddedHeader('set-cookie', 'three');

        self::assertSame(['Set-Cookie' => ['one', 'two', 'three']], $response->getHeaders());
        self::assertSame('one, two, three', $response->getHeaderLine('SET-COOKIE'));
    }

    #[Test]
    public function replacingAHeaderUsesTheNewNameAndRemovingOneIsCaseInsensitive(): void
    {
        $response = (new Response())->withHeader('x-request-id', 'a')->withHeader('X-Request-Id', 'b');

        self::assertSame(['X-Request-Id' => ['b']], $response->getHeaders());
        self::assertSame([], $response->withoutHeader('X-REQUEST-ID')->getHeaders());
        self::assertSame($response->getHeaders(), $response->withoutHeader('X-Absent')->getHeaders());
        self::assertSame([], $response->getHeader('X-Absent'));
        self::assertSame('', $response->getHeaderLine('X-Absent'));
    }

    #[Test]
    public function constructorHeadersAddUpLikeWithAddedHeader(): void
    {
        $request = new Request('GET', '/', ['Accept' => 'text/html', 'accept' => ['application/json'], 100 => 'numeric-name']);

        self::assertSame(['text/html', 'application/json'], $request->getHeader('Accept'));
        self::assertSame(['numeric-name'], $request->getHeader('100'));
    }

    // ── Request line ─────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['GET POST'];
        yield 'line feed' => ["GET\n"];
        yield 'slash' => ['GET/'];
    }

    #[Test]
    #[DataProvider('invalidMethods')]
    public function methodsMustBeTokens(string $method): void
    {
        self::assertInvalid(HttpCode::InvalidMethod, static fn() => new Request($method));
        self::assertInvalid(HttpCode::InvalidMethod, static fn() => (new Request())->withMethod($method));
        self::assertInvalid(HttpCode::InvalidMethod, static fn() => new ServerRequest($method));
    }

    #[Test]
    public function theMethodKeepsItsCase(): void
    {
        self::assertSame('patch', (new Request('patch'))->getMethod());
        self::assertSame('M-SEARCH', (new Request())->withMethod('M-SEARCH')->getMethod());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRequestTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['/a b'];
        yield 'line feed' => ["/a\nHost: evil"];
        yield 'tab' => ["/a\tb"];
        yield 'DEL' => ["/a\x7F"];
    }

    #[Test]
    #[DataProvider('invalidRequestTargets')]
    public function requestTargetsAreOneWordWithoutControls(string $target): void
    {
        self::assertInvalid(HttpCode::InvalidRequestTarget, static fn() => (new Request())->withRequestTarget($target));
    }

    #[Test]
    public function everyRequestTargetFormIsAccepted(): void
    {
        foreach (['*', 'example.com:443', 'http://example.com/a?b', '/path?q=1'] as $target) {
            self::assertSame($target, (new Request())->withRequestTarget($target)->getRequestTarget());
        }
    }

    #[Test]
    public function theDefaultTargetComesFromTheUri(): void
    {
        self::assertSame('/', (new Request('GET', ''))->getRequestTarget());
        self::assertSame('/users?page=2', (new Request('GET', 'https://api.example.com/users?page=2#top'))->getRequestTarget());
        self::assertSame('/rootless', (new Request('GET', (new Uri())->withPath('rootless')))->getRequestTarget());
        self::assertSame('/?q=1', (new Request('GET', '?q=1'))->getRequestTarget());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProtocolVersions(): iterable
    {
        yield 'three parts' => ['1.1.1'];
        yield 'with prefix' => ['HTTP/1.1'];
        yield 'empty' => [''];
        yield 'two digits' => ['11'];
        yield 'trailing line feed' => ["1.1\n"];
        yield 'letter' => ['x'];
    }

    #[Test]
    #[DataProvider('invalidProtocolVersions')]
    public function protocolVersionsAreADigitAndAnOptionalMinor(string $version): void
    {
        self::assertInvalid(HttpCode::InvalidProtocolVersion, static fn() => (new Response())->withProtocolVersion($version));
        self::assertInvalid(HttpCode::InvalidProtocolVersion, static fn() => new Response(protocolVersion: $version));
        self::assertInvalid(HttpCode::InvalidProtocolVersion, static fn() => new Request(protocolVersion: $version));
    }

    #[Test]
    public function validProtocolVersionsAreKept(): void
    {
        foreach (['1.0', '1.1', '2', '3'] as $version) {
            self::assertSame($version, (new Response())->withProtocolVersion($version)->getProtocolVersion());
        }
    }

    // ── Host ─────────────────────────────────────────────────────────────

    #[Test]
    public function theHostHeaderComesFromTheUriAndIsFirst(): void
    {
        $request = new Request('GET', 'https://api.example.com:8443/x', ['Accept' => 'text/html']);

        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
        self::assertSame('api.example.com:8443', $request->getHeaderLine('Host'));
        self::assertSame('api.example.com', (new Request('GET', 'https://api.example.com:443/'))->getHeaderLine('Host'), 'the default port is left out');
    }

    #[Test]
    public function anExplicitHostHeaderWinsOverTheUriAtConstruction(): void
    {
        $request = new Request('GET', 'https://a.example/', ['host' => 'b.example']);

        self::assertSame(['b.example'], $request->getHeader('Host'));
    }

    #[Test]
    public function withUriReplacesTheHostUnlessAskedToPreserveIt(): void
    {
        $request = new Request('GET', 'https://a.example/');
        $other = new Uri('https://b.example:8080/');

        self::assertSame('b.example:8080', $request->withUri($other)->getHeaderLine('Host'));
        self::assertSame('a.example', $request->withUri($other, true)->getHeaderLine('Host'));
        self::assertSame('b.example:8080', (new Request('GET', '/x'))->withUri($other, true)->getHeaderLine('Host'), 'nothing to preserve');
        self::assertSame('a.example', $request->withUri(new Uri('/relative'))->getHeaderLine('Host'), 'a URI without host leaves Host alone');
        self::assertSame('Host', array_key_first($request->withHeader('X-A', 'b')->withUri($other)->getHeaders()), 'Host stays first');
    }

    // ── Status line ──────────────────────────────────────────────────────

    #[Test]
    public function statusCodesRunFrom100To599(): void
    {
        foreach ([99, 600, 0, -200] as $code) {
            self::assertInvalid(HttpCode::InvalidStatusCode, static fn() => new Response($code));
            self::assertInvalid(HttpCode::InvalidStatusCode, static fn() => (new Response())->withStatus($code));
        }

        self::assertSame(100, (new Response(100))->getStatusCode());
        self::assertSame(599, (new Response())->withStatus(599)->getStatusCode());
    }

    #[Test]
    public function reasonPhrasesCannotBreakTheStatusLine(): void
    {
        self::assertInvalid(HttpCode::InvalidReasonPhrase, static fn() => new Response(200, "OK\r\nX: y"));
        self::assertInvalid(HttpCode::InvalidReasonPhrase, static fn() => (new Response())->withStatus(200, "OK\0"));
    }

    #[Test]
    public function theReasonPhraseDefaultsToTheRegisteredOne(): void
    {
        self::assertSame('Not Found', (new Response(404))->getReasonPhrase());
        self::assertSame('Teapot', (new Response())->withStatus(418, 'Teapot')->getReasonPhrase());
        self::assertSame('', (new Response(599))->getReasonPhrase(), 'unregistered codes have none');
        self::assertSame('Created', (new Response(200, 'Fine'))->withStatus(201)->getReasonPhrase(), 'a new status drops the old phrase');
    }

    // ── Bodies ───────────────────────────────────────────────────────────

    #[Test]
    public function aMessageWithoutBodyGetsAnEmptyStream(): void
    {
        $response = new Response();
        $body = Stream::fromString('x');

        self::assertSame('', (string) $response->getBody());
        self::assertSame($body, $response->withBody($body)->getBody());
        self::assertNotSame($response, $response->withBody($body));
    }

    private static function assertInvalid(HttpCode $code, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('the argument was accepted');
        } catch (InvalidMessageException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertInstanceOf(\InvalidArgumentException::class, $e, 'PSR-7 asks for \InvalidArgumentException');
        }
    }

    // ── Immutability ─────────────────────────────────────────────────────

    #[Test]
    public function everyWitherLeavesTheOriginalAlone(): void
    {
        $original = new Request('GET', 'https://a.example/x', ['X-A' => 'one']);
        $body = Stream::fromString('body');

        $original->withAddedHeader('X-A', ['two', 'three']);
        $original->withHeader('X-B', 'b');
        $original->withoutHeader('X-A');
        $original->withBody($body);
        $original->withProtocolVersion('2');
        $original->withRequestTarget('*');
        $original->withMethod('POST');
        $original->withUri(new Uri('https://c.example/'));

        self::assertSame(['Host' => ['a.example'], 'X-A' => ['one']], $original->getHeaders());
        self::assertSame('', (string) $original->getBody());
        self::assertSame('1.1', $original->getProtocolVersion());
        self::assertSame('/x', $original->getRequestTarget());
        self::assertSame('GET', $original->getMethod());
        self::assertSame('https://a.example/x', (string) $original->getUri());
    }

    #[Test]
    public function addingSeveralValuesKeepsThemAll(): void
    {
        $request = (new Request())->withHeader('X-A', 'one')->withAddedHeader('x-a', ['two', 'three']);

        self::assertSame(['one', 'two', 'three'], $request->getHeader('X-A'));
    }

    #[Test]
    public function aHostHeaderGivenInLowerCaseIsReplacedNotDuplicated(): void
    {
        $request = (new Request('GET', 'https://a.example/', ['host' => 'b.example', 'Accept' => '*/*']))->withUri(new Uri('https://c.example/'));

        self::assertSame(['Host' => ['c.example'], 'Accept' => ['*/*']], $request->getHeaders());
    }
}
