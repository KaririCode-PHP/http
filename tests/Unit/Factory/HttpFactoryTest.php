<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Factory;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Factory\HttpFactory;
use KaririCode\Http\Message\Request;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\UploadedFile;
use KaririCode\Http\Message\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

#[CoversClass(HttpFactory::class)]
#[CoversClass(InvalidMessageException::class)]
#[CoversClass(StreamException::class)]
final class HttpFactoryTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // ── PSR-17 Interface Compliance ──────────────────────────────────────

    public function testImplementsAllPsr17Interfaces(): void
    {
        self::assertInstanceOf(RequestFactoryInterface::class, $this->factory);
        self::assertInstanceOf(ResponseFactoryInterface::class, $this->factory);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $this->factory);
        self::assertInstanceOf(StreamFactoryInterface::class, $this->factory);
        self::assertInstanceOf(UploadedFileFactoryInterface::class, $this->factory);
        self::assertInstanceOf(UriFactoryInterface::class, $this->factory);
    }

    // ── createRequest ────────────────────────────────────────────────────

    public function testCreateRequestWithStringUri(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/users');

        self::assertInstanceOf(Request::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('api.example.com', $request->getUri()->getHost());
        self::assertSame('/users', $request->getUri()->getPath());
    }

    public function testCreateRequestWithUriObject(): void
    {
        $uri = new Uri('https://example.com/test');
        $request = $this->factory->createRequest('GET', $uri);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/test', $request->getUri()->getPath());
    }

    // ── createResponse ───────────────────────────────────────────────────

    public function testCreateResponseDefault(): void
    {
        $response = $this->factory->createResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
    }

    public function testCreateResponseWithCodeAndPhrase(): void
    {
        $response = $this->factory->createResponse(404, 'Resource Missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Resource Missing', $response->getReasonPhrase());
    }

    // ── createServerRequest ──────────────────────────────────────────────

    public function testCreateServerRequest(): void
    {
        $request = $this->factory->createServerRequest('PUT', '/api/v1/items', ['SERVER_NAME' => 'localhost']);

        self::assertInstanceOf(ServerRequest::class, $request);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/api/v1/items', $request->getUri()->getPath());
        self::assertSame('localhost', $request->getServerParams()['SERVER_NAME']);
    }

    // ── createStream ─────────────────────────────────────────────────────

    public function testCreateStreamFromString(): void
    {
        $stream = $this->factory->createStream('Hello World');

        self::assertInstanceOf(Stream::class, $stream);
        self::assertSame('Hello World', (string) $stream);
    }

    public function testCreateStreamFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'http_test_');
        file_put_contents($tmp, 'file content');

        try {
            $stream = $this->factory->createStreamFromFile($tmp);
            self::assertSame('file content', (string) $stream);
        } finally {
            @unlink($tmp);
        }
    }

    public function testCreateStreamFromFileThrowsOnMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->factory->createStreamFromFile('/nonexistent/path/file.txt');
    }

    public function testCreateStreamFromResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, 'from resource');
        rewind($resource);

        $stream = $this->factory->createStreamFromResource($resource);

        self::assertSame('from resource', (string) $stream);
    }

    // ── createUploadedFile ───────────────────────────────────────────────

    public function testCreateUploadedFile(): void
    {
        $stream = $this->factory->createStream('file data');
        $uploaded = $this->factory->createUploadedFile($stream, 9, UPLOAD_ERR_OK, 'doc.txt', 'text/plain');

        self::assertInstanceOf(UploadedFile::class, $uploaded);
        self::assertSame(9, $uploaded->getSize());
        self::assertSame(UPLOAD_ERR_OK, $uploaded->getError());
        self::assertSame('doc.txt', $uploaded->getClientFilename());
        self::assertSame('text/plain', $uploaded->getClientMediaType());
    }

    // ── createUri ────────────────────────────────────────────────────────

    public function testCreateUri(): void
    {
        $uri = $this->factory->createUri('https://example.com:8080/path?q=1#f');

        self::assertInstanceOf(Uri::class, $uri);
        self::assertSame('https', $uri->getScheme());
        self::assertSame(8080, $uri->getPort());
    }

    public function testCreateUriEmpty(): void
    {
        $uri = $this->factory->createUri();

        self::assertSame('', (string) $uri);
    }

    #[Test]
    public function aModePhpWouldMisreadIsAnInvalidArgument(): void
    {
        foreach (['', 'z', 'rw', 'read', 'R'] as $mode) {
            try {
                $this->factory->createStreamFromFile(__FILE__, $mode);
                self::fail('mode ' . $mode . ' was accepted');
            } catch (InvalidMessageException $e) {
                self::assertSame(HttpCode::InvalidStreamMode, $e->errorCode);
            }
        }

        self::assertTrue($this->factory->createStreamFromFile(__FILE__, 'rb')->isReadable());
        self::assertFalse($this->factory->createStreamFromFile(__FILE__, 'rbe')->isWritable());
    }

    #[Test]
    public function aFileThatCannotBeOpenedIsARuntimeFailure(): void
    {
        foreach (['', "/tmp/a\0b", __DIR__ . '/no/such/file'] as $filename) {
            try {
                $this->factory->createStreamFromFile($filename);
                self::fail('a missing file was opened');
            } catch (StreamException $e) {
                self::assertSame(HttpCode::StreamOpenFailed, $e->errorCode);
            }
        }
    }

    #[Test]
    public function anUploadedFileTakesItsSizeFromTheStreamWhenNoneIsGiven(): void
    {
        $stream = Stream::fromString('12345');

        self::assertSame(5, $this->factory->createUploadedFile($stream)->getSize());
        self::assertSame(9, $this->factory->createUploadedFile($stream, 9)->getSize());
        self::assertSame(UPLOAD_ERR_OK, $this->factory->createUploadedFile($stream)->getError());
    }
}
