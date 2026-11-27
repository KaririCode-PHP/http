<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Exception;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\CodeBlock;
use KaririCode\Http\Exception\EmitterException;
use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\InvalidUriException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Exception\UploadedFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpCode::class)]
#[CoversClass(EmitterException::class)]
#[CoversClass(InvalidConfigurationException::class)]
#[CoversClass(InvalidMessageException::class)]
#[CoversClass(InvalidUriException::class)]
#[CoversClass(StreamException::class)]
#[CoversClass(UploadedFileException::class)]
final class ExceptionCatalogueTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): \Throwable, HttpCode, class-string, string}>
     */
    public static function factories(): iterable
    {
        yield 'headers already sent' => [static fn() => EmitterException::headersAlreadySent('/app/index.php', 3), HttpCode::HeadersAlreadySent, Runtime::class, 'Output started at "/app/index.php" line 3, so the response headers cannot be sent.'];
        yield 'cors wildcard with credentials' => [static fn() => InvalidConfigurationException::corsWildcardWithCredentials(), HttpCode::CorsWildcardWithCredentials, Configuration::class, 'CORS with credentials needs explicit origins; "*" would let any site read credentialed responses.'];
        yield 'cors origin' => [static fn() => InvalidConfigurationException::corsOrigin('https://a.example/'), HttpCode::InvalidCorsOrigin, Configuration::class, '"https://a.example/"'];
        yield 'negative max-age' => [static fn() => InvalidConfigurationException::negativeMaxAge(), HttpCode::NegativeMaxAge, Configuration::class, 'The CORS max-age must be zero or'];
        yield 'trusted proxy' => [static fn() => InvalidConfigurationException::trustedProxy('proxy.internal'), HttpCode::InvalidTrustedProxy, Configuration::class, 'The trusted proxy "proxy.internal" is not an IP address or a CIDR range.'];
        yield 'buffer size' => [static fn() => InvalidConfigurationException::bufferSize(), HttpCode::InvalidBufferSize, Configuration::class, 'The emitter buffer size must be at least 1 byte.'];
        yield 'verify depth' => [static fn() => InvalidConfigurationException::verifyDepth(), HttpCode::InvalidVerifyDepth, Configuration::class, 'The certificate verify depth must be at least 1.'];
        yield 'unresolvable middleware' => [static fn() => InvalidConfigurationException::unresolvableMiddleware('auth'), HttpCode::UnresolvableMiddleware, Configuration::class, 'The route middleware "auth" does not resolve to a PSR-15 middleware.'];
        yield 'header name' => [static fn() => InvalidMessageException::headerName(), HttpCode::InvalidHeaderName, Validation::class, 'A header name must be an RFC 9110 token.'];
        yield 'header value' => [static fn() => InvalidMessageException::headerValue('X-A'), HttpCode::InvalidHeaderValue, Validation::class, 'Header "X-A" needs'];
        yield 'method' => [static fn() => InvalidMessageException::method(), HttpCode::InvalidMethod, Validation::class, 'A request method must be an RFC 9110 token.'];
        yield 'protocol version' => [static fn() => InvalidMessageException::protocolVersion(), HttpCode::InvalidProtocolVersion, Validation::class, 'A protocol version is a digit'];
        yield 'request target' => [static fn() => InvalidMessageException::requestTarget(), HttpCode::InvalidRequestTarget, Validation::class, 'A request target must not be empty'];
        yield 'status code' => [static fn() => InvalidMessageException::statusCode(), HttpCode::InvalidStatusCode, Validation::class, 'A status code must be between 100 and 599.'];
        yield 'reason phrase' => [static fn() => InvalidMessageException::reasonPhrase(), HttpCode::InvalidReasonPhrase, Validation::class, 'A reason phrase must not contain CR, LF'];
        yield 'uploaded files' => [static fn() => InvalidMessageException::uploadedFiles(), HttpCode::InvalidUploadedFiles, Validation::class, 'Uploaded files must be a tree of'];
        yield 'parsed body' => [static fn() => InvalidMessageException::parsedBody(), HttpCode::InvalidParsedBody, Validation::class, 'A parsed body must be an array, an object or'];
        yield 'target path' => [static fn() => InvalidMessageException::targetPath(), HttpCode::InvalidTargetPath, Validation::class, 'The target path of an uploaded file must not'];
        yield 'not a stream resource' => [static fn() => InvalidMessageException::notAStreamResource(), HttpCode::NotAStreamResource, Validation::class, 'A stream needs an open stream resource.'];
        yield 'not a server request' => [static fn() => InvalidMessageException::notAServerRequest(), HttpCode::NotAServerRequest, Validation::class, 'call canNormalize() first'];
        yield 'stream mode' => [static fn() => InvalidMessageException::streamMode('rw'), HttpCode::InvalidStreamMode, Validation::class, 'The stream mode "rw" is not'];
        yield 'unparsable uri' => [static fn() => InvalidUriException::unparsable(), HttpCode::UnparsableUri, Validation::class, 'The URI cannot be parsed.'];
        yield 'scheme' => [static fn() => InvalidUriException::scheme(), HttpCode::InvalidScheme, Validation::class, 'A scheme starts with a letter'];
        yield 'host' => [static fn() => InvalidUriException::host(), HttpCode::InvalidHost, Validation::class, 'A host holds a registered name'];
        yield 'port' => [static fn() => InvalidUriException::port(), HttpCode::InvalidPort, Validation::class, 'A port must be between 0 and 65535.'];
        yield 'stream detached' => [static fn() => StreamException::detached(), HttpCode::StreamDetached, Runtime::class, 'The stream is detached.'];
        yield 'stream not seekable' => [static fn() => StreamException::notSeekable(), HttpCode::StreamNotSeekable, Runtime::class, 'The stream is not seekable.'];
        yield 'stream not readable' => [static fn() => StreamException::notReadable(), HttpCode::StreamNotReadable, Runtime::class, 'The stream is not readable.'];
        yield 'stream not writable' => [static fn() => StreamException::notWritable(), HttpCode::StreamNotWritable, Runtime::class, 'The stream is not writable.'];
        yield 'stream operation failed' => [static fn() => StreamException::operationFailed('seek'), HttpCode::StreamOperationFailed, Runtime::class, 'The stream operation "seek" failed.'];
        yield 'stream open failed' => [static fn() => StreamException::openFailed('php://temp'), HttpCode::StreamOpenFailed, Runtime::class, 'The stream "php://temp" could not be opened.'];
        yield 'upload error' => [static fn() => UploadedFileException::uploadError(UPLOAD_ERR_PARTIAL), HttpCode::UploadError, Runtime::class, 'The upload failed with UPLOAD_ERR_PARTIAL.'];
        yield 'already moved' => [static fn() => UploadedFileException::alreadyMoved(), HttpCode::UploadAlreadyMoved, Runtime::class, 'The uploaded file was already moved.'];
        yield 'move failed' => [static fn() => UploadedFileException::moveFailed(), HttpCode::UploadMoveFailed, Runtime::class, 'The uploaded file could not be moved to the target path.'];
    }

    /**
     * @param \Closure(): \Throwable $factory
     * @param class-string           $family
     */
    #[Test]
    #[DataProvider('factories')]
    public function everyFactoryCarriesItsCodeFamilyAndPrefixedMessage(\Closure $factory, HttpCode $code, string $family, string $message): void
    {
        $exception = $factory();

        self::assertInstanceOf($family, $exception);
        self::assertSame($code->value, $exception->getCode());
        self::assertTrue(property_exists($exception, 'errorCode'));
        self::assertSame($code, $exception->errorCode);
        self::assertStringStartsWith('[HTTP_' . $code->value . '] ', $exception->getMessage());
        self::assertStringContainsString($message, $exception->getMessage());
    }

    #[Test]
    public function everyCodeHasAFactoryAndSitsInTheHttpBlock(): void
    {
        $used = array_map(static fn(array $row): HttpCode => $row[1], iterator_to_array(self::factories()));

        self::assertEqualsCanonicalizing(HttpCode::cases(), array_values($used), 'one factory per code, no orphan codes');
        self::assertSame('HTTP', HttpCode::prefix());
        self::assertEquals(new CodeBlock(1700, 1799), HttpCode::block());

        foreach (HttpCode::cases() as $code) {
            self::assertTrue(HttpCode::block()->contains($code->value), $code->name);
        }
    }

    #[Test]
    public function psr7ArgumentErrorsAreInvalidArgumentExceptions(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, InvalidMessageException::headerName());
        self::assertInstanceOf(\InvalidArgumentException::class, InvalidUriException::host());
        self::assertInstanceOf(\RuntimeException::class, StreamException::detached());
        self::assertInstanceOf(\RuntimeException::class, UploadedFileException::moveFailed());
    }

    #[Test]
    public function controlCharactersInAQuotedIdentifierAreEscaped(): void
    {
        $exception = InvalidConfigurationException::unresolvableMiddleware("auth\r\nX-Injected: 1");

        self::assertStringNotContainsString("\n", $exception->getMessage());
        self::assertStringContainsString('"auth\r\nX-Injected: 1"', $exception->getMessage());
    }
}
