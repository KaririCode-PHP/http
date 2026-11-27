<?php

declare(strict_types=1);

namespace KaririCode\Http\Factory;

use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Message\Request;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Silently;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\UploadedFile;
use KaririCode\Http\Message\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * All six PSR-17 factories in one object, building this package's
 * messages, so code written against PSR-17 gets the validated
 * implementations. createStreamFromFile() opens what the application names;
 * it is never a path a client chose.
 *
 * ARFA 1.3: P4 (code depends on PSR-17, not on these classes). Spec V4.3.3:
 * final, stateless factory.
 *
 * Preconditions: arguments follow the message rules (ADR-005);
 * createStreamFromFile() throws InvalidMessageException 1712 for a mode PHP
 * would misread (PSR-17 asks for \InvalidArgumentException) and
 * StreamException 1735 when the file cannot be opened.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: createStreamFromFile() opens a file.
 *
 * Example:
 *
 *     $factory = new HttpFactory();
 *     $response = $factory->createResponse(404)->withBody($factory->createStream('{"error":"not found"}'));
 *
 * References: PSR-17 HTTP Factories; ADR-001.
 *
 * @see https://www.php-fig.org/psr/psr-17/
 */
final class HttpFactory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
    /** A mode as PHP reads it: the access letter, then "+" and the b, t, e and n flags. "rw" would open read-only. */
    private const string FILE_MODE = '/\A[rwaxc][+bten]*\z/';

    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }

    #[\Override]
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, $reasonPhrase);
    }

    /**
     * @param UriInterface|string     $uri
     * @param array<array-key, mixed> $serverParams
     */
    #[\Override]
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, serverParams: $serverParams);
    }

    #[\Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::fromString($content);
    }

    #[\Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if (preg_match(self::FILE_MODE, $mode) !== 1) {
            throw InvalidMessageException::streamMode($mode);
        }

        if ($filename === '' || str_contains($filename, "\0")) {
            throw StreamException::openFailed($filename);
        }

        $resource = Silently::run(static fn(): mixed => fopen($filename, $mode));

        return is_resource($resource) ? new Stream($resource) : throw StreamException::openFailed($filename);
    }

    #[\Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }

    #[\Override]
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        return new UploadedFile($stream, $size ?? $stream->getSize(), $error, $clientFilename, $clientMediaType);
    }

    #[\Override]
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
