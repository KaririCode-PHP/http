<?php

declare(strict_types=1);

namespace KaririCode\Http\Factory;

use KaririCode\Http\Message\Request;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
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
 * Unified PSR-17 factory implementing all six factory interfaces.
 *
 * Single DI registration satisfies RequestFactoryInterface,
 * ResponseFactoryInterface, ServerRequestFactoryInterface,
 * StreamFactoryInterface, UploadedFileFactoryInterface, and
 * UriFactoryInterface. Avoids the six-class proliferation pattern
 * common in other PSR-17 implementations.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): all factory
 * methods return new immutable objects.
 *
 * Spec V4.0: final class, no inheritance, zero state.
 *
 * @see https://www.php-fig.org/psr/psr-17/ PSR-17 HTTP Factories
 */
final class HttpFactory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, is_string($uri) ? new Uri($uri) : $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, $reasonPhrase);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest(
            $method,
            is_string($uri) ? new Uri($uri) : $uri,
            serverParams: $serverParams,
        );
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::fromString($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $resource = @fopen($filename, $mode);
        if ($resource === false) {
            throw new \RuntimeException("Unable to open file: {$filename}");
        }

        return new Stream($resource);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }

    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        return new UploadedFile(
            $stream,
            $size ?? $stream->getSize() ?? 0,
            $error,
            $clientFilename,
            $clientMediaType,
        );
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}

