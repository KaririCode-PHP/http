<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploaded file representation (PSR-7 §3.4).
 *
 * Not readonly: PSR-7 mandates tracking the "moved" state to prevent
 * double-move, which requires mutable internal state. This is the
 * only mutable message-layer type alongside Stream (Spec V4.0
 * exemption: PSR-7 compliance takes precedence over readonly).
 *
 * In CLI/test mode, uses rename() instead of move_uploaded_file()
 * since is_uploaded_file() returns false outside SAPI context.
 *
 * ARFA 1.3 exemption: mutable moved flag required by PSR-7.
 *
 * @see https://www.php-fig.org/psr/psr-7/#36-psrhttpmessageuploadedfileinterface PSR-7 UploadedFile
 */
final class UploadedFile implements UploadedFileInterface
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_OK => 'No error',
        UPLOAD_ERR_INI_SIZE => 'Exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'Exceeds MAX_FILE_SIZE form directive',
        UPLOAD_ERR_PARTIAL => 'Partial upload',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
        UPLOAD_ERR_EXTENSION => 'Blocked by extension',
    ];

    private bool $moved = false;

    public function __construct(
        private StreamInterface|string $source,
        private readonly int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
    }

    public function getStream(): StreamInterface
    {
        $this->assertValid();

        if ($this->source instanceof StreamInterface) {
            return $this->source;
        }

        return new Stream(fopen($this->source, 'r'));
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertValid();

        if ($targetPath === '') {
            throw new \InvalidArgumentException('Target path must not be empty');
        }

        if ($this->source instanceof StreamInterface) {
            $target = fopen($targetPath, 'w');
            if ($target === false) {
                throw new \RuntimeException("Unable to open target: {$targetPath}");
            }

            $this->source->rewind();
            while (!$this->source->eof()) {
                fwrite($target, $this->source->read(8192));
            }
            fclose($target);
        } else {
            $sapi = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server';
            $success = $sapi
                ? rename($this->source, $targetPath)
                : move_uploaded_file($this->source, $targetPath);

            if (!$success) {
                throw new \RuntimeException("Unable to move file to: {$targetPath}");
            }
        }

        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertValid(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                'Upload error: ' . (self::UPLOAD_ERRORS[$this->error] ?? 'Unknown'),
            );
        }

        if ($this->moved) {
            throw new \RuntimeException('File has already been moved');
        }
    }
}

