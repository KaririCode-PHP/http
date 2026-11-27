<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use Psr\Http\Message\StreamInterface;

/**
 * A PHP stream behind PSR-7's StreamInterface. Readable and writable come
 * from the mode the resource was opened with, read as PHP reads it: the
 * first letter ("r" reads; "w", "a", "x" or "c" writes) and "+" for both,
 * so every mode PHP accepts is classified, "rw" (read-only) included. The stream owns its resource: close(),
 * and the destructor, close it; detach() hands it back to the caller.
 *
 * ARFA 1.3: P2 (the byte stage of a message). Spec V4.3.3: final PSR-7
 * adapter; the getX() methods and __toString() are the PSR-7 contract.
 *
 * Preconditions: the constructor receives an open stream resource
 * (InvalidMessageException 1710). Operations on a detached stream, or
 * ones the mode does not allow, throw StreamException (1730 to 1734).
 *
 * Thread-safety: holds a resource; use it from one fiber at a time.
 *
 * Side effects: reads, writes and seeks move the resource's position;
 * close() and the destructor close the resource.
 *
 * Example:
 *
 *     $body = Stream::fromString('{"ok":true}');
 *     $body->getContents();   // '{"ok":true}'
 *
 * References: PSR-7 StreamInterface; PHP manual, fopen() modes; ADR-001.
 *
 * @see https://www.php.net/manual/en/function.fopen.php
 */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private mixed $resource;

    private ?int $size = null;

    private bool $seekable;

    private bool $readable;

    private bool $writable;

    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw InvalidMessageException::notAStreamResource();
        }

        $meta = stream_get_meta_data($resource);
        $both = str_contains($meta['mode'], '+');
        $access = substr($meta['mode'], 0, 1);
        $this->resource = $resource;
        $this->seekable = $meta['seekable'];
        $this->readable = $both || $access === 'r';
        $this->writable = $both || in_array($access, ['w', 'a', 'x', 'c'], true);
    }

    public static function fromString(string $content): self
    {
        $stream = self::empty();
        $stream->write($content);
        $stream->rewind();

        return $stream;
    }

    public static function empty(): self
    {
        return new self(fopen('php://temp', 'r+'));
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The whole stream from the start; '' when it cannot be read (PSR-7 §3.4). */
    #[\Override]
    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (StreamException) {
            return '';
        }
    }

    #[\Override]
    public function close(): void
    {
        $resource = $this->detach();

        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * @return resource|null
     */
    #[\Override]
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

    #[\Override]
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        if ($this->size === null) {
            $stats = fstat($this->resource);
            $this->size = $stats === false ? null : $stats['size'];
        }

        return $this->size;
    }

    #[\Override]
    public function tell(): int
    {
        $position = ftell($this->attached());

        return $position === false ? throw StreamException::operationFailed('tell') : $position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $resource = $this->attached();

        if (!$this->seekable) {
            throw StreamException::notSeekable();
        }

        if (fseek($resource, $offset, $whence) === -1) {
            throw StreamException::operationFailed('seek');
        }
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->writable;
    }

    #[\Override]
    public function write(string $string): int
    {
        $resource = $this->attached();

        if (!$this->writable) {
            throw StreamException::notWritable();
        }

        $this->size = null;
        $written = Silently::run(static fn(): int|false => fwrite($resource, $string));

        return $written === false ? throw StreamException::operationFailed('write') : $written;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /** Up to $length bytes; '' for a length of 0. */
    #[\Override]
    public function read(int $length): string
    {
        $resource = $this->attached();

        if (!$this->readable) {
            throw StreamException::notReadable();
        }

        if ($length < 0) {
            throw StreamException::operationFailed('read');
        }

        if ($length === 0) {
            return '';
        }

        $data = Silently::run(static fn(): string|false => fread($resource, $length));

        return $data === false ? throw StreamException::operationFailed('read') : $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $resource = $this->attached();

        if (!$this->readable) {
            throw StreamException::notReadable();
        }

        $contents = stream_get_contents($resource);

        return $contents === false ? throw StreamException::operationFailed('getContents') : $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    /**
     * @return resource
     */
    private function attached(): mixed
    {
        return $this->resource ?? throw StreamException::detached();
    }
}
