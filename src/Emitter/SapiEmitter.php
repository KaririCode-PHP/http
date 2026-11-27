<?php

declare(strict_types=1);

namespace KaririCode\Http\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Emits a PSR-7 Response through the PHP SAPI layer.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): terminal pipeline stage
 * that converts the in-memory Response into actual HTTP output.
 *
 * Sends status line, headers, and streamed body to the client.
 * Reads body in configurable chunks to bound memory usage on
 * large responses. Aborts early on client disconnect.
 *
 * Spec V4.0: final class, no inheritance, single responsibility.
 *
 * @see https://www.php-fig.org/psr/psr-7/ PSR-7 HTTP Message Interface
 */
final class SapiEmitter
{
    public function __construct(
        private readonly int $bufferSize = 8192,
    ) {
    }

    public function emit(ResponseInterface $response): void
    {
        $this->assertNotSent();
        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    private function emitStatusLine(ResponseInterface $response): void
    {
        $code = $response->getStatusCode();
        $phrase = $response->getReasonPhrase();
        $version = $response->getProtocolVersion();

        header(
            "HTTP/{$version} {$code} {$phrase}",
            true,
            $code,
        );
    }

    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->bufferSize);

            if (connection_aborted()) {
                break;
            }
        }
    }

    private function assertNotSent(): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Headers already sent in {$file} on line {$line}",
            );
        }
    }
}

