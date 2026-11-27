<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Uri;
use KaririCode\Http\Middleware\TrailingSlashMiddleware;
use KaririCode\Http\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrailingSlashMiddleware::class)]
final class TrailingSlashMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirects(): iterable
    {
        yield 'one slash' => ['https://example.com/users/', '/users'];
        yield 'several slashes' => ['https://example.com/users///', '/users'];
        yield 'query kept' => ['https://example.com/users/?page=2&sort=name', '/users?page=2&sort=name'];
        yield 'nested path' => ['https://example.com/a/b/c/', '/a/b/c'];
        yield 'only slashes' => ['https://example.com//', '/'];
        yield 'protocol-relative attempt' => ['https://example.com//evil.example/', '/evil.example'];
        yield 'backslash stays encoded' => ['https://example.com/%5Cevil.example/', '/%5Cevil.example'];
    }

    #[Test]
    #[DataProvider('redirects')]
    public function getAndHeadAreRedirectedToTheTrimmedPath(string $uri, string $location): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $handler = new RecordingHandler();

            $response = (new TrailingSlashMiddleware())->process(new ServerRequest($method, $uri), $handler);

            self::assertSame(301, $response->getStatusCode());
            self::assertSame($location, $response->getHeaderLine('Location'));
            self::assertSame([], $handler->requests);
        }
    }

    #[Test]
    public function theLocationNeverNamesTheHostTheClientSent(): void
    {
        $request = ServerRequest::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/', 'HTTP_HOST' => 'evil.example']);

        $response = (new TrailingSlashMiddleware())->process($request, new RecordingHandler());

        self::assertSame('/users', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function otherMethodsContinueWithThePathRewrittenAndTheHostKept(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'get'] as $method) {
            $handler = new RecordingHandler();
            $request = new ServerRequest($method, 'https://example.com/users//?x=1', ['Host' => 'proxy.example']);

            $response = (new TrailingSlashMiddleware())->process($request, $handler);

            self::assertSame(200, $response->getStatusCode(), $method);
            self::assertSame('/users', $handler->last()->getUri()->getPath());
            self::assertSame('x=1', $handler->last()->getUri()->getQuery());
            self::assertSame('proxy.example', $handler->last()->getHeaderLine('Host'), 'the Host header is preserved');
        }
    }

    #[Test]
    public function withoutRedirectsEveryMethodIsRewritten(): void
    {
        $handler = new RecordingHandler();

        $response = (new TrailingSlashMiddleware(redirect: false))->process(new ServerRequest('GET', 'https://example.com/users/'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/users', $handler->last()->getUri()->getPath());
    }

    #[Test]
    public function pathsWithoutATrailingSlashPassUntouched(): void
    {
        foreach (['https://example.com/', 'https://example.com/users', 'https://example.com'] as $uri) {
            $handler = new RecordingHandler();
            $request = new ServerRequest('GET', $uri);

            (new TrailingSlashMiddleware())->process($request, $handler);

            self::assertSame($request, $handler->last(), $uri);
        }
    }

    #[Test]
    public function aRootlessPathGainsItsLeadingSlash(): void
    {
        $handler = new RecordingHandler();
        $request = new ServerRequest('POST', (new Uri())->withPath('users/'));

        (new TrailingSlashMiddleware())->process($request, $handler);

        self::assertSame('/users', $handler->last()->getUri()->getPath());
    }
}
