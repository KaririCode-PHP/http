<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Exception\RuntimeException;

/**
 * An uploaded file cannot be read or moved: PHP reported an upload error,
 * the file was already moved, or the move itself failed. PSR-7 asks for
 * \RuntimeException, which the Runtime family base extends.
 *
 * Preconditions: messages never repeat the client's file name or the
 * target path; uploadError() names PHP's UPLOAD_ERR_* constant.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $file->moveTo($storage . '/' . bin2hex(random_bytes(16)));
 *     } catch (UploadedFileException $e) {
 *         // HttpCode::UploadError: the client's upload was incomplete
 *     }
 *
 * References: PSR-7 UploadedFileInterface; PHP manual, file upload error
 * messages; KaririCode Spec V4.3.3 §8.4; ADR-003.
 *
 * @see https://www.php.net/manual/en/features.file-upload.errors.php
 */
final class UploadedFileException extends RuntimeException
{
    private const array UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'UPLOAD_ERR_INI_SIZE',
        UPLOAD_ERR_FORM_SIZE => 'UPLOAD_ERR_FORM_SIZE',
        UPLOAD_ERR_PARTIAL => 'UPLOAD_ERR_PARTIAL',
        UPLOAD_ERR_NO_FILE => 'UPLOAD_ERR_NO_FILE',
        UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
        UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
        UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
    ];

    public static function uploadError(int $error): self
    {
        return new self(HttpCode::UploadError, sprintf('The upload failed with %s.', self::UPLOAD_ERRORS[$error] ?? 'an unknown error'));
    }

    public static function alreadyMoved(): self
    {
        return new self(HttpCode::UploadAlreadyMoved, 'The uploaded file was already moved.');
    }

    public static function moveFailed(): self
    {
        return new self(HttpCode::UploadMoveFailed, 'The uploaded file could not be moved to the target path.');
    }
}
