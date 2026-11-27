<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 stream wrapper around a PHP resource.
 *
 * Implements lazy reading and deferred resource acquisition.
 * Not immutable — streams are inherently stateful per PSR-7 §1.3.
 * This is the only PSR-7 type exempt from ARFA 1.3 P1 immutability.
 *
 * Read/write mode lookup tables use hash maps for O(1) detection,
 * covering all POSIX fopen mode combinations including binary/text.
 * Spec V4.0 exemption: mutable by PSR-7 requirement (resource wrapper).
 *
 * @see https://www.php-fig.org/psr/psr-7/#35-psrhttpmessagestreaminterface PSR-7 Stream
 */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private ?int $size = null;

    private bool $seekable;
    private bool $readable;
    private bool $writable;

    private const READ_MODES = [
        'r' => true, 'r+' => true, 'w+' => true, 'a+' => true,
        'x+' => true, 'c+' => true, 'rb' => true, 'r+b' => true,
        'w+b' => true, 'a+b' => true, 'x+b' => true, 'c+b' => true,
        'rt' => true, 'r+t' => true, 'w+t' => true, 'a+t' => true,
        'x+t' => true, 'c+t' => true,
    ];

    private const WRITE_MODES = [
        'w' => true, 'w+' => true, 'r+' => true, 'a' => true,
        'a+' => true, 'x' => true, 'x+' => true, 'c' => true,
        'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
        'ab' => true, 'a+b' => true, 'xb' => true, 'x+b' => true,
        'cb' => true, 'c+b' => true, 'wt' => true, 'w+t' => true,
        'r+t' => true, 'at' => true, 'a+t' => true, 'xt' => true,
        'x+t' => true, 'ct' => true, 'c+t' => true,
    ];

    /**
     * @param resource $resource PHP stream resource
      *
      * @see https://www.php-fig.org/psr/psr-7/#35-psrhttpmessagestreaminterface PSR-7 Stream
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Expected a stream resource');
        }

        $this->resource = $resource;
        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'];

        $this->seekable = $meta['seekable'];
        $this->readable = isset(self::READ_MODES[$mode]);
        $this->writable = isset(self::WRITE_MODES[$mode]);
    }

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);

        return new self($resource);
    }

    public static function empty(): self
    {
        return new self(fopen('php://temp', 'r+'));
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            $resource = $this->detach();
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->seekable = false;
        $this->readable = false;
        $this->writable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        if ($this->size !== null) {
            return $this->size;
        }

        $stats = fstat($this->resource);
        $this->size = $stats['size'] ?? null;

        return $this->size;
    }

    public function tell(): int
    {
        $this->assertAttached();

        $position = ftell($this->resource);
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new \RuntimeException("Unable to seek to position {$offset}");
        }

        $this->size = null;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        $this->assertAttached();

        if (!$this->writable) {
            throw new \RuntimeException('Stream is not writable');
        }

        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        $this->size = null;

        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new \RuntimeException('Stream is not readable');
        }

        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $data;
    }

    public function getContents(): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new \RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }
    }
}

