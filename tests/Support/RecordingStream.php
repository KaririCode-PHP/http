<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A stream decorator that records each read() length and can pretend not
 * to seek or not to be readable.
 */
final class RecordingStream implements StreamInterface
{
    /** @var list<int> */
    public private(set) array $reads = [];

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly bool $seekable = true,
        private readonly bool $readable = true,
        private readonly ?string $asString = null,
    ) {}

    /** The inner stream's text, or $asString without moving the inner stream. */
    #[\Override]
    public function __toString(): string
    {
        return $this->asString ?? (string) $this->inner;
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[\Override]
    public function detach()
    {
        return $this->inner->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->inner->rewind();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    #[\Override]
    public function read(int $length): string
    {
        $this->reads[] = $length;

        return $this->inner->read($length);
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
