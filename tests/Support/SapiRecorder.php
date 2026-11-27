<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

/**
 * Stands in for the SAPI while SapiEmitter runs under PHPUnit: the CLI
 * keeps no header list, so tests/Support/sapi-functions.php routes the
 * emitter's header(), headers_sent() and connection_status() calls here.
 * The state is static because the functions it replaces are global.
 */
final class SapiRecorder
{
    /** @var list<array{string, bool, int}> header line, replace, response code */
    public static array $headers = [];

    /** @var array{string, int}|null where output started, or null when nothing was sent */
    public static ?array $outputStartedAt = null;

    /** The number of connection checks that report CONNECTION_NORMAL; null keeps it normal. */
    public static ?int $checksBeforeAbort = null;

    public static function reset(): void
    {
        self::$headers = [];
        self::$outputStartedAt = null;
        self::$checksBeforeAbort = null;
    }

    public static function header(string $line, bool $replace, int $code): void
    {
        self::$headers[] = [$line, $replace, $code];
    }

    /**
     * @param-out string $file
     * @param-out int    $line
     */
    public static function headersSent(mixed &$file, mixed &$line): bool
    {
        [$file, $line] = self::$outputStartedAt ?? ['', 0];

        return self::$outputStartedAt !== null;
    }

    public static function connectionStatus(): int
    {
        if (self::$checksBeforeAbort === null) {
            return CONNECTION_NORMAL;
        }

        return self::$checksBeforeAbort-- > 0 ? CONNECTION_NORMAL : CONNECTION_ABORTED;
    }
}
