<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Exception\RuntimeException;

/**
 * The SAPI emitter cannot send the response: output started before the
 * status line and headers, so PHP can no longer send them.
 *
 * Preconditions: the message names the file and line PHP reports, a server
 * location, through quote().
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         (new SapiEmitter())->emit($response);
 *     } catch (EmitterException) {
 *         // something echoed before the emitter ran
 *     }
 *
 * References: PHP manual, headers_sent(); KaririCode Spec V4.3.3 §8.4;
 * ADR-003.
 *
 * @see https://www.php.net/manual/en/function.headers-sent.php
 */
final class EmitterException extends RuntimeException
{
    public static function headersAlreadySent(string $file, int $line): self
    {
        return new self(HttpCode::HeadersAlreadySent, sprintf('Output started at %s line %d, so the response headers cannot be sent.', self::quote($file), $line));
    }
}
