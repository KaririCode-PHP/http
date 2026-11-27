<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Message\Stream;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'stream_');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    // ── Constructor ──────────────────────────────────────────────────────

    public function testConstructorRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Stream('not a resource');
    }

    public function testConstructorAcceptsStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
    }

    // ── Read / Write ─────────────────────────────────────────────────────

    public function testWriteAndRead(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        $bytes = $stream->write('Hello World');
        self::assertSame(11, $bytes);

        $stream->rewind();
        self::assertSame('Hello World', $stream->read(11));
    }

    public function testGetContents(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('test data');
        $stream->rewind();

        self::assertSame('test data', $stream->getContents());
    }

    public function testToString(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('cast to string');

        self::assertSame('cast to string', (string) $stream);
    }

    // ── Size ─────────────────────────────────────────────────────────────

    public function testGetSize(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('12345');

        self::assertSame(5, $stream->getSize());
    }

    public function testGetSizeReturnsNullForDetachedStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->detach();

        self::assertNull($stream->getSize());
    }

    // ── Position ─────────────────────────────────────────────────────────

    public function testTellReturnsCurrentPosition(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('abc');

        self::assertSame(3, $stream->tell());
    }

    public function testSeek(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('Hello');
        $stream->seek(2);

        self::assertSame(2, $stream->tell());
        self::assertSame('llo', $stream->getContents());
    }

    public function testRewind(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('abc');
        $stream->rewind();

        self::assertSame(0, $stream->tell());
    }

    public function testEof(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('x');
        $stream->rewind();
        $stream->read(1);

        self::assertTrue($stream->eof());
    }

    // ── Capabilities ─────────────────────────────────────────────────────

    public function testReadOnlyStream(): void
    {
        file_put_contents($this->tmpFile, 'readonly');
        $resource = fopen($this->tmpFile, 'r');
        $stream = new Stream($resource);

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
    }

    public function testWriteOnlyStream(): void
    {
        $resource = fopen($this->tmpFile, 'w');
        $stream = new Stream($resource);

        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->isWritable());
    }

    public function testSeekableStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        self::assertTrue($stream->isSeekable());
    }

    // ── Detach / Close ───────────────────────────────────────────────────

    public function testDetachReturnsResourceAndNullsInternal(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        $detached = $stream->detach();
        self::assertIsResource($detached);
        self::assertNull($stream->detach());
    }

    public function testDetachedStreamIsNotReadable(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->detach();

        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
    }

    public function testCloseReleasesResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->close();

        self::assertNull($stream->detach());
        self::assertSame('', (string) $stream);
    }

    public function testReadAfterDetachThrows(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('data');
        $stream->detach();

        $this->expectException(\RuntimeException::class);
        $stream->read(1);
    }

    // ── Metadata ─────────────────────────────────────────────────────────

    public function testGetMetadataReturnsArray(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        $meta = $stream->getMetadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('mode', $meta);
    }

    public function testGetMetadataWithKey(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        self::assertSame('r+', $stream->getMetadata('mode'));
    }

    public function testGetMetadataUnknownKeyReturnsNull(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);

        self::assertNull($stream->getMetadata('nonexistent'));
    }

    public function testToStringReturnsEmptyAfterDetach(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = new Stream($resource);
        $stream->write('data');
        $stream->detach();

        self::assertSame('', (string) $stream);
    }
}
