<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Silently;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\UploadedFile;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerRequest::class)]
#[CoversClass(UploadedFile::class)]
#[CoversClass(Silently::class)]
final class ServerRequestFromServerTest extends TestCase
{
    private const array SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/orders?page=2',
        'SERVER_PROTOCOL' => 'HTTP/2',
        'HTTP_HOST' => 'shop.example.com',
        'SERVER_NAME' => 'internal.example',
        'SERVER_PORT' => '8080',
        'HTTPS' => 'on',
        'HTTP_X_REQUEST_ID' => 'abc-123',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => '17',
        'REMOTE_ADDR' => '203.0.113.9',
    ];

    // ── Request line and URI ─────────────────────────────────────────────

    #[Test]
    public function itBuildsTheRequestLineAndUri(): void
    {
        $request = ServerRequest::fromServer(self::SERVER);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('2', $request->getProtocolVersion());
        self::assertSame('https://shop.example.com/orders?page=2', (string) $request->getUri());
        self::assertSame('/orders?page=2', $request->getRequestTarget());
        self::assertSame(self::SERVER, $request->getServerParams());
    }

    #[Test]
    public function headersComeFromHttpVariablesAndContentHeaders(): void
    {
        $request = ServerRequest::fromServer(self::SERVER);

        self::assertSame('abc-123', $request->getHeaderLine('X-Request-Id'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('17', $request->getHeaderLine('Content-Length'));
        self::assertSame('shop.example.com', $request->getHeaderLine('Host'));
        self::assertFalse($request->hasHeader('Remote-Addr'));
        self::assertFalse($request->hasHeader('Server-Name'));
    }

    #[Test]
    public function missingVariablesFallBackToSafeDefaults(): void
    {
        $request = ServerRequest::fromServer([]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('http:/', (string) $request->getUri());
        self::assertSame('/', $request->getRequestTarget());
        self::assertSame([], $request->getHeaders());
    }

    #[Test]
    public function variablesOfTheWrongTypeCountAsMissing(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_METHOD' => ['POST'], 'REQUEST_URI' => 42, 'HTTP_HOST' => null, 'HTTP_X_A' => ['x']]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getRequestTarget());
        self::assertFalse($request->hasHeader('X-A'));
    }

    // ── Host and scheme ──────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostHeadersThatAreNotHostAndPort(): iterable
    {
        yield 'user info' => ['shop.example.com@evil.test'];
        yield 'path' => ['shop.example.com/evil'];
        yield 'query' => ['evil.test?shop.example.com'];
        yield 'fragment' => ['evil.test#shop'];
        yield 'space' => ['evil.test shop'];
        yield 'port out of range' => ['shop.example.com:99999'];
        yield 'port not a number' => ['shop.example.com:http'];
        yield 'empty port' => ['shop.example.com:'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('hostHeadersThatAreNotHostAndPort')]
    public function aHostHeaderThatIsNotHostAndPortIsIgnoredForTheUri(string $host): void
    {
        $request = ServerRequest::fromServer(['HTTP_HOST' => $host] + self::SERVER);

        self::assertSame('internal.example', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
    }

    #[Test]
    public function aValidHostHeaderSetsHostAndPort(): void
    {
        $withPort = ServerRequest::fromServer(['HTTP_HOST' => 'Shop.Example.com:8443'] + self::SERVER);
        $literal = ServerRequest::fromServer(['HTTP_HOST' => '[2001:db8::1]:8443'] + self::SERVER);

        self::assertSame('shop.example.com', $withPort->getUri()->getHost());
        self::assertSame(8443, $withPort->getUri()->getPort());
        self::assertSame('[2001:db8::1]', $literal->getUri()->getHost());
        self::assertSame(8443, $literal->getUri()->getPort());
    }

    #[Test]
    public function withoutAHostHeaderTheServerNameAndPortAreUsed(): void
    {
        $server = self::SERVER;
        unset($server['HTTP_HOST']);
        $request = ServerRequest::fromServer($server);

        self::assertSame('https://internal.example:8080/orders?page=2', (string) $request->getUri());
        self::assertSame('internal.example:8080', $request->getHeaderLine('Host'));
    }

    #[Test]
    public function anUnusableServerPortLeavesThePortOut(): void
    {
        foreach (['eighty', '70000', '', '-1'] as $port) {
            $uri = ServerRequest::fromServer(['SERVER_NAME' => 'internal.example', 'SERVER_PORT' => $port])->getUri();

            self::assertSame('internal.example', $uri->getHost(), $port);
            self::assertNull($uri->getPort(), $port);
        }

        self::assertSame(65535, ServerRequest::fromServer(['SERVER_NAME' => 'internal.example', 'SERVER_PORT' => '65535'])->getUri()->getPort());
        self::assertSame(65535, ServerRequest::fromServer(['HTTP_HOST' => 'a.example:65535'])->getUri()->getPort());
        self::assertSame('', ServerRequest::fromServer(['SERVER_NAME' => 'not a host'])->getUri()->getHost());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function schemes(): iterable
    {
        yield 'HTTPS on' => [['HTTPS' => 'on'], 'https'];
        yield 'HTTPS 1' => [['HTTPS' => '1'], 'https'];
        yield 'HTTPS off' => [['HTTPS' => 'off'], 'http'];
        yield 'HTTPS OFF' => [['HTTPS' => 'OFF'], 'http'];
        yield 'HTTPS empty' => [['HTTPS' => ''], 'http'];
        yield 'REQUEST_SCHEME' => [['REQUEST_SCHEME' => 'HTTPS'], 'https'];
        yield 'REQUEST_SCHEME http' => [['REQUEST_SCHEME' => 'http'], 'http'];
        yield 'forwarded header is not believed' => [['HTTP_X_FORWARDED_PROTO' => 'https'], 'http'];
        yield 'nothing' => [[], 'http'];
    }

    /**
     * @param array<string, string> $server
     */
    #[Test]
    #[DataProvider('schemes')]
    public function theSchemeComesFromTheServerOnly(array $server, string $scheme): void
    {
        self::assertSame($scheme, ServerRequest::fromServer($server + ['HTTP_HOST' => 'a.example'])->getUri()->getScheme());
    }

    // ── Request target forms ─────────────────────────────────────────────

    #[Test]
    public function theAsteriskFormIsKeptAsTheTarget(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '*', 'HTTP_HOST' => 'a.example']);

        self::assertSame('*', $request->getRequestTarget());
        self::assertSame('', $request->getUri()->getPath());
    }

    #[Test]
    public function theAbsoluteFormGivesItsPathAndQueryButNotItsAuthority(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_URI' => 'http://evil.test/a/b?c=d', 'HTTP_HOST' => 'a.example']);
        $noPath = ServerRequest::fromServer(['REQUEST_URI' => 'http://evil.test', 'HTTP_HOST' => 'a.example']);

        self::assertSame('http://a.example/a/b?c=d', (string) $request->getUri());
        self::assertSame('/', $noPath->getUri()->getPath());
    }

    #[Test]
    public function aTargetThatIsNotOriginFormBecomesTheRoot(): void
    {
        foreach (['relative/path', 'http:///broken', ''] as $uri) {
            self::assertSame('/', ServerRequest::fromServer(['REQUEST_URI' => $uri])->getUri()->getPath(), $uri);
        }
    }

    #[Test]
    public function aQueryWithoutPathKeepsTheQuery(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_URI' => '/?a=1&b=2']);

        self::assertSame('/', $request->getUri()->getPath());
        self::assertSame('a=1&b=2', $request->getUri()->getQuery());
    }

    // ── Header leniency ──────────────────────────────────────────────────

    #[Test]
    public function headersThatBreakTheGrammarAreSkippedInsteadOfFailingTheRequest(): void
    {
        $request = ServerRequest::fromServer([
            'HTTP_X_GOOD' => 'fine',
            'HTTP_X_BAD' => "value\nInjected: yes",
            'HTTP_X_BAD_NAME' => 'fine',
            'HTTP_' => 'nameless',
            'HTTP_X_EMPTY' => '',
            'HTTP_X(Y)' => 'bad name',
        ]);

        self::assertSame(['X-Good' => ['fine'], 'X-Bad-Name' => ['fine']], $request->getHeaders());
    }

    #[Test]
    public function theRedirectedAuthorizationHeaderIsRecovered(): void
    {
        $recovered = ServerRequest::fromServer(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']);
        $direct = ServerRequest::fromServer(['HTTP_AUTHORIZATION' => 'Basic xyz', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']);
        $unsafe = ServerRequest::fromServer(['REDIRECT_HTTP_AUTHORIZATION' => "Bearer abc\r\nX: y"]);

        self::assertSame('Bearer abc', $recovered->getHeaderLine('Authorization'));
        self::assertSame('Basic xyz', $direct->getHeaderLine('Authorization'));
        self::assertFalse($unsafe->hasHeader('Authorization'));
    }

    #[Test]
    public function anUnreadableProtocolFallsBackTo11(): void
    {
        foreach (['HTTP/1.0' => '1.0', 'HTTP/3' => '3', 'HTTP/1.1.1' => '1.1', 'SPDY/3' => '1.1', 'http/2' => '1.1'] as $protocol => $version) {
            self::assertSame($version, ServerRequest::fromServer(['SERVER_PROTOCOL' => $protocol])->getProtocolVersion(), $protocol);
        }
    }

    #[Test]
    public function theBodyIsTheStreamGiven(): void
    {
        $body = Stream::fromString('{"a":1}');

        self::assertSame($body, ServerRequest::fromServer([], $body)->getBody());
    }

    // ── Globals ──────────────────────────────────────────────────────────

    #[Test]
    #[BackupGlobals(true)]
    public function fromGlobalsReadsEverySuperglobal(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/form?x=1', 'HTTP_HOST' => 'a.example'];
        $_COOKIE = ['session' => 'abc'];
        $_GET = ['x' => '1'];
        $_POST = ['name' => 'Ana'];
        $_FILES = ['avatar' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/none', 'error' => UPLOAD_ERR_OK, 'size' => 3]];

        $request = ServerRequest::fromGlobals();

        self::assertSame('POST', $request->getMethod());
        self::assertSame(['session' => 'abc'], $request->getCookieParams());
        self::assertSame(['x' => '1'], $request->getQueryParams());
        self::assertSame(['name' => 'Ana'], $request->getParsedBody());
        self::assertInstanceOf(UploadedFile::class, $request->getUploadedFiles()['avatar']);
        self::assertSame('php://input', $request->getBody()->getMetadata('uri'));
    }

    #[Test]
    #[BackupGlobals(true)]
    public function anEmptyPostLeavesTheParsedBodyNull(): void
    {
        $_SERVER = [];
        $_POST = [];
        $_FILES = [];

        self::assertNull(ServerRequest::fromGlobals()->getParsedBody());
    }

    // ── Typed withers ────────────────────────────────────────────────────

    #[Test]
    public function uploadedFilesMustBeATreeOfUploadedFiles(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1, UPLOAD_ERR_OK);
        $request = new ServerRequest();

        self::assertSame(['a' => ['b' => [$file]]], $request->withUploadedFiles(['a' => ['b' => [$file]]])->getUploadedFiles());

        foreach ([['a' => 'not a file'], ['a' => [$file, ['deep' => 42]]]] as $tree) {
            try {
                $request->withUploadedFiles($tree);
                self::fail('an invalid tree was accepted');
            } catch (InvalidMessageException $e) {
                self::assertSame(HttpCode::InvalidUploadedFiles, $e->errorCode);
            }
        }
    }

    #[Test]
    public function aParsedBodyIsAnArrayAnObjectOrNull(): void
    {
        $request = new ServerRequest();
        $object = new \stdClass();

        self::assertSame($object, $request->withParsedBody($object)->getParsedBody());
        self::assertNull($request->withParsedBody(['a' => 1])->withParsedBody(null)->getParsedBody());

        foreach (['text', 42, true] as $invalid) {
            try {
                $request->withParsedBody($invalid);
                self::fail('a scalar parsed body was accepted');
            } catch (InvalidMessageException $e) {
                self::assertSame(HttpCode::InvalidParsedBody, $e->errorCode);
            }
        }
    }

    #[Test]
    public function attributesHoldAnyValueIncludingNull(): void
    {
        $request = (new ServerRequest())->withAttribute('nullable', null)->withAttribute('id', 7);

        self::assertNull($request->getAttribute('nullable', 'default'), 'a stored null is not the default');
        self::assertSame('default', $request->getAttribute('absent', 'default'));
        self::assertSame(['nullable' => null, 'id' => 7], $request->getAttributes());
        self::assertSame(['id' => 7], $request->withoutAttribute('nullable')->getAttributes());
    }

    #[Test]
    public function anOriginFormTargetWithoutQueryIsThePath(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_URI' => '/users/42', 'HTTP_HOST' => 'a.example']);

        self::assertSame('/users/42', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('/users/42', $request->getRequestTarget());
    }
}
