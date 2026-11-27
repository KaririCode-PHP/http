<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Exception\UploadedFileException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A file the client uploaded (PSR-7 UploadedFileInterface), backed by the
 * temporary file PHP wrote or by a stream. Under a web SAPI, and under the
 * built-in server, a temporary file moves only through
 * move_uploaded_file(), which refuses any path PHP did not receive as an
 * upload; only the CLI, where no upload exists, falls back to rename(). A
 * stream source is copied, and a short write fails the move instead of
 * leaving a truncated file. The client's file name and media type are
 * kept as given: they are client data, never a path to write to.
 *
 * ARFA 1.3: P1 (state changes only from "not moved" to "moved"). Spec
 * V4.3.3: final PSR-7 value object; the getX() methods are the PSR-7
 * contract.
 *
 * Preconditions: $error is one of PHP's UPLOAD_ERR_* values; moveTo() needs
 * a non-empty target path without NUL (InvalidMessageException 1709) and a
 * file that uploaded without error and was not moved yet
 * (UploadedFileException 1740, 1741).
 *
 * Thread-safety: moveTo() changes the state; use the object from one fiber.
 *
 * Side effects: moveTo() writes the target file and removes the temporary
 * one.
 *
 * Example:
 *
 *     foreach ($request->getUploadedFiles() as $file) {
 *         $file->moveTo($storage . '/' . bin2hex(random_bytes(16)));
 *     }
 *
 * References: PSR-7 UploadedFileInterface; PHP manual,
 * move_uploaded_file(); OWASP File Upload Cheat Sheet; ADR-005.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
 */
final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface|string $source,
        private readonly ?int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {}

    /**
     * Turns PHP's $_FILES layout, including the per-field arrays of a
     * multiple upload, into a tree of UploadedFile objects.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    public static function treeFromGlobals(array $files): array
    {
        $tree = [];

        foreach (array_keys($files) as $field) {
            $tree[$field] = self::node($files[$field]);
        }

        return $tree;
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        $this->assertMovable();

        if ($this->source instanceof StreamInterface) {
            return $this->source;
        }

        $source = $this->source;
        $resource = Silently::run(static fn(): mixed => fopen($source, 'r'));

        return is_resource($resource) ? new Stream($resource) : throw StreamException::openFailed('uploaded file');
    }

    #[\Override]
    public function moveTo(string $targetPath): void
    {
        $this->assertMovable();

        if ($targetPath === '' || str_contains($targetPath, "\0")) {
            throw InvalidMessageException::targetPath();
        }

        $moved = $this->source instanceof StreamInterface
            ? self::copy($this->source, $targetPath)
            : self::moveTemporaryFile($this->source, $targetPath);

        if (!$moved) {
            throw UploadedFileException::moveFailed();
        }

        $this->moved = true;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size;
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private static function node(mixed $value): UploadedFileInterface|array
    {
        return match (true) {
            $value instanceof UploadedFileInterface => $value,
            is_array($value) && isset($value['tmp_name']) => self::fromSpec($value),
            is_array($value) => self::treeFromGlobals($value),
            default => throw InvalidMessageException::uploadedFiles(),
        };
    }

    /**
     * @param array<array-key, mixed> $spec one $_FILES entry: name, type, tmp_name, error, size
     *
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private static function fromSpec(array $spec): UploadedFileInterface|array
    {
        if (is_array($spec['tmp_name'])) {
            $files = [];

            foreach (array_keys($spec['tmp_name']) as $key) {
                $files[$key] = self::fromSpec(array_map(static fn(mixed $field): mixed => is_array($field) ? ($field[$key] ?? null) : null, $spec));
            }

            return $files;
        }

        $spec += ['size' => null, 'error' => UPLOAD_ERR_NO_FILE, 'name' => null, 'type' => null];

        return new self(
            self::stringOrNull($spec['tmp_name']) ?? '',
            self::intOrNull($spec['size']),
            self::intOrNull($spec['error']) ?? UPLOAD_ERR_NO_FILE,
            self::stringOrNull($spec['name']),
            self::stringOrNull($spec['type']),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function assertMovable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw UploadedFileException::uploadError($this->error);
        }

        if ($this->moved) {
            throw UploadedFileException::alreadyMoved();
        }
    }

    private static function copy(StreamInterface $source, string $targetPath): bool
    {
        $target = Silently::run(static fn(): mixed => fopen($targetPath, 'w'));

        if (!is_resource($target)) {
            return false;
        }

        try {
            if ($source->isSeekable()) {
                $source->rewind();
            }

            while (!$source->eof()) {
                $chunk = $source->read(8192);

                if (Silently::run(static fn(): int|false => fwrite($target, $chunk)) !== strlen($chunk)) {
                    return false;
                }
            }

            return true;
        } finally {
            fclose($target);
        }
    }

    private static function moveTemporaryFile(string $source, string $targetPath): bool
    {
        return Silently::run(static fn(): bool => PHP_SAPI === 'cli' ? rename($source, $targetPath) : move_uploaded_file($source, $targetPath));
    }
}
