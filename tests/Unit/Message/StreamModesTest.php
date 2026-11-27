<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Message\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stream::class)]
#[CoversClass(StreamException::class)]
#[CoversClass(InvalidMessageException::class)]
final class StreamModesTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kariricode-http-stream');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function modes(): iterable
    {
        yield 'r' => ['r', true, false];
        yield 'rb' => ['rb', true, false];
        yield 'r+' => ['r+', true, true];
        yield 'w' => ['w', false, true];
        yield 'wb' => ['wb', false, true];
        yield 'w+' => ['w+', true, true];
        yield 'a' => ['a', false, true];
        yield 'a+' => ['a+', true, true];
        yield 'c' => ['c', false, true];
        yield 'c+b' => ['c+b', true, true];
        yield 'rb+ (plus after the flag)' => ['rb+', true, true];
        yield 'rw opens read-only' => ['rw', true, false];
    }

    #[Test]
    #[DataProvider('modes')]
    public function readableAndWritableFollowTheOpenMode(string $mode, bool $readable, bool $writable): void
    {
        $resource = fopen($this->file, $mode);
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        self::assertSame($readable, $stream->isReadable());
        self::assertSame($writable, $stream->isWritable());
        self::assertTrue($stream->isSeekable());
    }

    #[Test]
    public function theExclusiveCreateModeWrites(): void
    {
        unlink($this->file);
        $resource = fopen($this->file, 'x');
        self::assertIsResource($resource);

        self::assertTrue((new Stream($resource))->isWritable());
        self::assertFalse((new Stream(fopen($this->file, 'r')))->isWritable());
    }

    #[Test]
    public function onlyAnOpenStreamResourceIsAccepted(): void
    {
        $closed = fopen('php://memory', 'r');
        self::assertIsResource($closed);
        fclose($closed);

        foreach (['php://memory', stream_context_create(), $closed, null] as $candidate) {
            try {
                new Stream($candidate);
                self::fail(get_debug_type($candidate) . ' was accepted as a stream');
            } catch (InvalidMessageException $e) {
                self::assertSame(HttpCode::NotAStreamResource, $e->errorCode);
            }
        }
    }

    #[Test]
    public function readingZeroBytesReturnsNothingAndANegativeLengthFails(): void
    {
        $stream = Stream::fromString('abc');

        self::assertSame('', $stream->read(0));
        self::assertSame(0, $stream->tell());
        self::assertStreamFails(HttpCode::StreamOperationFailed, static fn(): string => $stream->read(-1));
    }

    #[Test]
    public function theModeIsEnforced(): void
    {
        $readOnly = new Stream(fopen(__FILE__, 'r'));
        $writeOnly = new Stream(fopen($this->file, 'w'));

        self::assertStreamFails(HttpCode::StreamNotWritable, static fn(): int => $readOnly->write('x'));
        self::assertStreamFails(HttpCode::StreamNotReadable, static fn(): string => $writeOnly->read(1));
        self::assertStreamFails(HttpCode::StreamNotReadable, static fn(): string => $writeOnly->getContents());
        self::assertSame('', (string) $writeOnly, '__toString() never throws');
    }

    #[Test]
    public function aStreamThatCannotSeekSaysSo(): void
    {
        $output = fopen('php://output', 'w');
        self::assertIsResource($output);
        $stream = new Stream($output);

        self::assertFalse($stream->isSeekable());
        self::assertStreamFails(HttpCode::StreamNotSeekable, static fn() => $stream->seek(0));
        self::assertStreamFails(HttpCode::StreamNotSeekable, static fn() => $stream->rewind());
    }

    #[Test]
    public function aSeekThatFailsThrows(): void
    {
        $stream = Stream::fromString('abc');

        self::assertStreamFails(HttpCode::StreamOperationFailed, static fn() => $stream->seek(-10));
        $stream->seek(-1, SEEK_END);
        self::assertSame(2, $stream->tell());
        self::assertSame('c', $stream->getContents());
    }

    #[Test]
    public function everyOperationOnADetachedStreamFails(): void
    {
        $stream = Stream::fromString('abc');
        $resource = $stream->detach();
        self::assertIsResource($resource);

        foreach ([static fn() => $stream->tell(), static fn() => $stream->seek(0), static fn() => $stream->read(1), static fn() => $stream->write('x'), static fn() => $stream->getContents()] as $operation) {
            self::assertStreamFails(HttpCode::StreamDetached, $operation);
        }

        self::assertTrue($stream->eof());
        self::assertNull($stream->getSize());
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('mode'));
        self::assertNull($stream->detach(), 'a second detach has nothing to give');
        self::assertSame('abc', stream_get_contents($resource, -1, 0), 'the caller owns the resource now');
    }

    #[Test]
    public function closingTwiceIsHarmlessAndTheDestructorClosesTheResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        $stream->close();
        $stream->close();

        self::assertFalse(is_resource($resource));

        $owned = fopen('php://memory', 'r+');
        self::assertIsResource($owned);
        $temporary = new Stream($owned);
        unset($temporary);
        self::assertFalse(is_resource($owned));
    }

    #[Test]
    public function theSizeIsRecountedAfterAWrite(): void
    {
        $stream = Stream::fromString('abc');

        self::assertSame(3, $stream->getSize());
        $stream->seek(0, SEEK_END);
        $stream->write('defg');
        self::assertSame(7, $stream->getSize());
        self::assertSame('abcdefg', (string) $stream);
    }

    #[Test]
    public function emptyStreamsAreReadableWritableAndEmpty(): void
    {
        $stream = Stream::empty();

        self::assertSame(0, $stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertSame('', (string) $stream);
        self::assertSame('php://temp', $stream->getMetadata('uri'));
    }

    #[Test]
    public function toStringReadsFromTheStartWithoutLosingData(): void
    {
        $stream = Stream::fromString('hello world');
        $stream->read(6);

        self::assertSame('hello world', (string) $stream);
        self::assertSame('hello world', (string) $stream, 'twice');
    }

    private static function assertStreamFails(HttpCode $code, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('the operation did not fail');
        } catch (StreamException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $e, 'PSR-7 asks for \RuntimeException');
        }
    }
}
