<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Exception\RuntimeException;

/**
 * A stream operation failed: the stream was detached, it does not support
 * the operation (seek, read, write), the underlying call failed, or a
 * stream could not be opened. PSR-7 asks for \RuntimeException, which the
 * Runtime family base extends.
 *
 * Preconditions: messages name the operation, never a path a client may
 * have chosen; openFailed() quotes the file name the application passed.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $body->seek(0);
 *     } catch (StreamException $e) {
 *         assert($e->errorCode === HttpCode::StreamNotSeekable || $e->errorCode === HttpCode::StreamOperationFailed);
 *     }
 *
 * References: PSR-7 StreamInterface; KaririCode Spec V4.3.3 §8.4; ADR-003.
 *
 * @see https://www.php-fig.org/psr/psr-7/#34-psrhttpmessagestreaminterface
 */
final class StreamException extends RuntimeException
{
    public static function detached(): self
    {
        return new self(HttpCode::StreamDetached, 'The stream is detached.');
    }

    public static function notSeekable(): self
    {
        return new self(HttpCode::StreamNotSeekable, 'The stream is not seekable.');
    }

    public static function notReadable(): self
    {
        return new self(HttpCode::StreamNotReadable, 'The stream is not readable.');
    }

    public static function notWritable(): self
    {
        return new self(HttpCode::StreamNotWritable, 'The stream is not writable.');
    }

    public static function operationFailed(string $operation): self
    {
        return new self(HttpCode::StreamOperationFailed, sprintf('The stream operation %s failed.', self::quote($operation)));
    }

    public static function openFailed(string $filename): self
    {
        return new self(HttpCode::StreamOpenFailed, sprintf('The stream %s could not be opened.', self::quote($filename)));
    }
}
