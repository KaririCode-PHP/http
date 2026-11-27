<?php

declare(strict_types=1);

/*
 * SapiEmitter calls header(), headers_sent() and connection_status()
 * without a leading backslash, so PHP looks for them in its namespace
 * first. Composer loads this file before any test (autoload-dev "files"),
 * so the emitter talks to SapiRecorder and the tests can see every header
 * line, which the CLI SAPI would otherwise drop.
 */

namespace KaririCode\Http\Emitter;

use KaririCode\Http\Tests\Support\SapiRecorder;

function header(string $header, bool $replace = true, int $response_code = 0): void
{
    SapiRecorder::header($header, $replace, $response_code);
}

/**
 * @param-out string $filename
 * @param-out int    $line
 */
function headers_sent(mixed &$filename = null, mixed &$line = null): bool
{
    return SapiRecorder::headersSent($filename, $line);
}

function connection_status(): int
{
    return SapiRecorder::connectionStatus();
}
