<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Emitter;

use KaririCode\Http\Emitter\SapiEmitter;
use KaririCode\Http\Exception\EmitterException;
use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Tests\Support\RecordingStream;
use KaririCode\Http\Tests\Support\SapiRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SapiEmitter::class)]
#[CoversClass(EmitterException::class)]
final class SapiEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        SapiRecorder::reset();
    }

    protected function tearDown(): void
    {
        SapiRecorder::reset();
    }

    #[Test]
    public function theStatusLineComesFirstWithTheResponseCode(): void
    {
        $this->emit(new SapiEmitter(), (new Response(201))->withProtocolVersion('2'));

        self::assertSame(['HTTP/2 201 Created', true, 201], SapiRecorder::$headers[0]);
    }

    #[Test]
    public function aStatusWithoutReasonPhraseHasNoTrailingSpace(): void
    {
        $this->emit(new SapiEmitter(), new Response(599));

        self::assertSame(['HTTP/1.1 599', true, 599], SapiRecorder::$headers[0]);
    }

    #[Test]
    public function theFirstValueOfAHeaderReplacesAndTheOthersAdd(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Set-Cookie', ['a=1', 'b=2', 'c=3']);

        $this->emit(new SapiEmitter(), $response);

        self::assertSame([
            ['HTTP/1.1 200 OK', true, 200],
            ['Content-Type: text/plain', true, 0],
            ['Set-Cookie: a=1', true, 0],
            ['Set-Cookie: b=2', false, 0],
            ['Set-Cookie: c=3', false, 0],
        ], SapiRecorder::$headers);
    }

    #[Test]
    public function theWholeBodyIsSentFromTheStartInChunksOfTheBufferSize(): void
    {
        $data = str_repeat('0123456789', 2000);
        $body = new RecordingStream(Stream::fromString($data));
        $body->read(500);

        $output = $this->emit(new SapiEmitter(4096), (new Response(200))->withBody($body));

        self::assertSame($data, $output);
        self::assertSame([500, 4096, 4096, 4096, 4096, 4096], $body->reads);
    }

    #[Test]
    public function aBodyThatCannotSeekIsSentFromWhereItStands(): void
    {
        $body = new RecordingStream(Stream::fromString('abcdef'), seekable: false);
        $body->read(2);

        self::assertSame('cdef', $this->emit(new SapiEmitter(), (new Response(200))->withBody($body)));
    }

    #[Test]
    public function aBodyThatCannotBeReadIsSentAsItsStringForm(): void
    {
        $body = new RecordingStream(Stream::fromString('never read'), readable: false, asString: 'fallback');

        self::assertSame('fallback', $this->emit(new SapiEmitter(), (new Response(200))->withBody($body)));
        self::assertSame([], $body->reads, 'read() is not called on a stream that cannot read');
    }

    #[Test]
    public function theDefaultBufferIs8KiB(): void
    {
        $body = new RecordingStream(Stream::fromString(str_repeat('x', 20000)));

        $this->emit(new SapiEmitter(), (new Response(200))->withBody($body));

        self::assertSame([8192, 8192, 8192], $body->reads);
    }

    #[Test]
    public function headerValuesWithKeysFromAnotherImplementationStillReplaceFirst(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getReasonPhrase')->willReturn('OK');
        $response->method('getProtocolVersion')->willReturn('1.1');
        $response->method('getHeaders')->willReturn(['Set-Cookie' => ['first' => 'a=1', 'second' => 'b=2']]);
        $response->method('getBody')->willReturn(Stream::fromString(''));

        $this->emit(new SapiEmitter(), $response);

        self::assertSame([['Set-Cookie: a=1', true, 0], ['Set-Cookie: b=2', false, 0]], array_slice(SapiRecorder::$headers, 1));
    }

    #[Test]
    public function anEmptyBodySendsNothing(): void
    {
        self::assertSame('', $this->emit(new SapiEmitter(), new Response(204)));
    }

    #[Test]
    public function sendingStopsWhenTheClientGoesAway(): void
    {
        SapiRecorder::$checksBeforeAbort = 2;
        $body = new RecordingStream(Stream::fromString(str_repeat('x', 100)));

        $output = $this->emit(new SapiEmitter(10), (new Response(200))->withBody($body));

        self::assertSame(str_repeat('x', 20), $output);
        self::assertSame([10, 10], $body->reads);
    }

    #[Test]
    public function nothingIsSentWhenOutputHasAlreadyStarted(): void
    {
        SapiRecorder::$outputStartedAt = ['/app/public/index.php', 12];

        try {
            (new SapiEmitter())->emit(new Response(200));
            self::fail('the emitter sent headers after output');
        } catch (EmitterException $e) {
            self::assertSame(HttpCode::HeadersAlreadySent, $e->errorCode);
            self::assertStringContainsString('"/app/public/index.php" line 12', $e->getMessage());
        }

        self::assertSame([], SapiRecorder::$headers);
    }

    #[Test]
    public function theBufferSizeIsAtLeastOneByte(): void
    {
        self::assertSame('ab', $this->emit(new SapiEmitter(1), (new Response(200))->withBody(Stream::fromString('ab'))));

        try {
            new SapiEmitter(0);
            self::fail('a zero buffer was accepted');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(HttpCode::InvalidBufferSize, $e->errorCode);
        }
    }

    private function emit(SapiEmitter $emitter, ResponseInterface $response): string
    {
        ob_start();

        try {
            $emitter->emit($response);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
