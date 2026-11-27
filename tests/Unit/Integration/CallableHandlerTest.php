<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Integration;

use KaririCode\Http\Integration\CallableHandler;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Message\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(CallableHandler::class)]
final class CallableHandlerTest extends TestCase
{
    #[Test]
    public function theHandlerReceivesTheRequest(): void
    {
        $request = (new ServerRequest('GET', '/users/7'))->withAttribute('id', '7');

        $response = (new CallableHandler(static fn(ServerRequestInterface $r): array => ['id' => $r->getAttribute('id')]))->handle($request);

        self::assertSame('{"id":"7"}', (string) $response->getBody());
    }

    #[Test]
    public function aResponsePassesThroughUntouched(): void
    {
        $custom = (new Response(201))->withHeader('X-Custom', 'value')->withBody(Stream::fromString('created'));

        $response = (new CallableHandler(static fn(): Response => $custom))->handle(new ServerRequest());

        self::assertSame($custom, $response);
    }

    /**
     * @return iterable<string, array{mixed, int, string, string}>
     */
    public static function results(): iterable
    {
        yield 'null is no content' => [null, 204, '', ''];
        yield 'a string is HTML' => ['<h1>Olá</h1>', 200, 'text/html; charset=utf-8', '<h1>Olá</h1>'];
        yield 'an empty string is an empty page' => ['', 200, 'text/html; charset=utf-8', ''];
        yield 'an array is JSON' => [['status' => 'ok', 'items' => [1, 2]], 200, 'application/json; charset=utf-8', '{"status":"ok","items":[1,2]}'];
        yield 'unicode stays readable' => [['name' => 'São Paulo'], 200, 'application/json; charset=utf-8', '{"name":"São Paulo"}'];
        yield 'an object is JSON' => [(object) ['a' => 1], 200, 'application/json; charset=utf-8', '{"a":1}'];
        yield 'an integer is text' => [42, 200, 'text/plain; charset=utf-8', '42'];
        yield 'a float is text' => [1.5, 200, 'text/plain; charset=utf-8', '1.5'];
        yield 'true is text' => [true, 200, 'text/plain; charset=utf-8', '1'];
        yield 'false is empty text' => [false, 200, 'text/plain; charset=utf-8', ''];
    }

    #[Test]
    #[DataProvider('results')]
    public function whatTheHandlerReturnsBecomesAResponse(mixed $result, int $status, string $contentType, string $body): void
    {
        $response = (new CallableHandler(static fn(): mixed => $result))->handle(new ServerRequest());

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $response->getBody());
    }

    #[Test]
    public function aResourceBecomesEmptyText(): void
    {
        $resource = fopen('php://memory', 'r');

        $response = (new CallableHandler(static fn(): mixed => $resource))->handle(new ServerRequest());

        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function somethingNotCallableIsAWiringErrorAnswered500(): void
    {
        foreach (['no_such_function_anywhere', 42, null, ['NoSuchClass', 'method']] as $target) {
            $response = (new CallableHandler($target))->handle(new ServerRequest());

            self::assertSame(500, $response->getStatusCode());
            self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertSame(['error' => 'Internal Server Error', 'status' => 500], json_decode((string) $response->getBody(), true));
        }
    }

    #[Test]
    public function aValueThatCannotBeEncodedThrows(): void
    {
        $this->expectException(\JsonException::class);

        (new CallableHandler(static fn(): array => ['bad' => "\xB1\x31"]))->handle(new ServerRequest());
    }
}
