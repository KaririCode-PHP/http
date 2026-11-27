<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/uploaded_file_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*") ?: []);
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function createUploadedFile(
        string $content = 'file content',
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = 'test.txt',
        ?string $clientMediaType = 'text/plain',
    ): UploadedFile {
        $tmp = tempnam($this->tmpDir, 'up_');
        file_put_contents($tmp, $content);

        $stream = new Stream(fopen($tmp, 'r'));

        return new UploadedFile($stream, strlen($content), $error, $clientFilename, $clientMediaType);
    }

    // ── Getters ──────────────────────────────────────────────────────────

    public function testGetSize(): void
    {
        $file = $this->createUploadedFile('hello');
        self::assertSame(5, $file->getSize());
    }

    public function testGetError(): void
    {
        $file = $this->createUploadedFile(error: UPLOAD_ERR_OK);
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
    }

    public function testGetClientFilename(): void
    {
        $file = $this->createUploadedFile(clientFilename: 'photo.jpg');
        self::assertSame('photo.jpg', $file->getClientFilename());
    }

    public function testGetClientMediaType(): void
    {
        $file = $this->createUploadedFile(clientMediaType: 'image/jpeg');
        self::assertSame('image/jpeg', $file->getClientMediaType());
    }

    public function testNullClientFilename(): void
    {
        $file = $this->createUploadedFile(clientFilename: null);
        self::assertNull($file->getClientFilename());
    }

    // ── getStream ────────────────────────────────────────────────────────

    public function testGetStream(): void
    {
        $file = $this->createUploadedFile('stream data');
        $stream = $file->getStream();

        self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $stream);
    }

    public function testGetStreamThrowsOnError(): void
    {
        $stream = new Stream(fopen('php://memory', 'r+'));
        $file = new UploadedFile($stream, 0, UPLOAD_ERR_INI_SIZE);

        $this->expectException(\RuntimeException::class);
        $file->getStream();
    }

    // ── moveTo ───────────────────────────────────────────────────────────

    public function testMoveTo(): void
    {
        $file = $this->createUploadedFile('move me');
        $target = $this->tmpDir . '/moved.txt';

        $file->moveTo($target);

        self::assertFileExists($target);
        self::assertSame('move me', file_get_contents($target));
    }

    public function testMoveToThrowsOnSecondMove(): void
    {
        $file = $this->createUploadedFile('once only');
        $file->moveTo($this->tmpDir . '/first.txt');

        $this->expectException(\RuntimeException::class);
        $file->moveTo($this->tmpDir . '/second.txt');
    }

    public function testMoveToThrowsOnError(): void
    {
        $stream = new Stream(fopen('php://memory', 'r+'));
        $file = new UploadedFile($stream, 0, UPLOAD_ERR_NO_FILE);

        $this->expectException(\RuntimeException::class);
        $file->moveTo($this->tmpDir . '/nope.txt');
    }

    public function testMoveToThrowsOnEmptyPath(): void
    {
        $file = $this->createUploadedFile('data');

        $this->expectException(\InvalidArgumentException::class);
        $file->moveTo('');
    }

    public function testGetStreamThrowsAfterMove(): void
    {
        $file = $this->createUploadedFile('data');
        $file->moveTo($this->tmpDir . '/moved.txt');

        $this->expectException(\RuntimeException::class);
        $file->getStream();
    }
}
