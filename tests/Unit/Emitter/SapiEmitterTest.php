<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Emitter;

use KaririCode\Http\Emitter\SapiEmitter;
use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\Stream;
use PHPUnit\Framework\TestCase;

/**
 * SapiEmitter unit tests.
 *
 * NOTE: These tests verify construction and configuration only.
 * Full emit() behavior requires integration testing with an actual
 * SAPI environment (headers_sent, header(), echo) which PHPUnit
 * cannot provide in CLI mode without output buffering tricks.
 */
final class SapiEmitterTest extends TestCase
{
    public function testConstructorAcceptsBufferSize(): void
    {
        // Verifies construction — no exceptions
        $emitter = new SapiEmitter(bufferSize: 4096);

        self::assertInstanceOf(SapiEmitter::class, $emitter);
    }

    public function testDefaultBufferSize(): void
    {
        $emitter = new SapiEmitter();

        self::assertInstanceOf(SapiEmitter::class, $emitter);
    }

    /**
     * Verifies that the emitter detects already-sent headers.
     *
     * In CLI mode, headers_sent() returns false, so this test
     * exercises the happy path (no exception thrown).
     */
    public function testEmitInCliModeDoesNotThrow(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(Stream::fromString('Hello'));

        $emitter = new SapiEmitter();

        // In CLI, headers are never "sent", so this should not throw.
        // We capture output to prevent it from polluting test output.
        ob_start();
        try {
            $emitter->emit($response);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertSame('Hello', $output);
    }

    public function testEmitEmptyBodyProducesNoOutput(): void
    {
        $response = new Response(204);
        $emitter = new SapiEmitter();

        ob_start();
        $emitter->emit($response);
        $output = ob_get_clean();

        self::assertSame('', $output);
    }

    public function testEmitLargeBodyStreamedInChunks(): void
    {
        $data = str_repeat('A', 20000);
        $response = (new Response(200))->withBody(Stream::fromString($data));
        $emitter = new SapiEmitter(bufferSize: 4096);

        ob_start();
        $emitter->emit($response);
        $output = ob_get_clean();

        self::assertSame(20000, strlen($output));
        self::assertSame($data, $output);
    }
}
