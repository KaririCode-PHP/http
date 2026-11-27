<?php

declare(strict_types=1);

namespace KaririCode\Http\Emitter;

use KaririCode\Http\Exception\EmitterException;
use KaririCode\Http\Exception\InvalidConfigurationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response through the PHP SAPI: the status line, every
 * header value (the first of each name replaces what PHP set before, so a
 * framework's default Content-Type does not linger; the rest are added),
 * then the body in chunks of $bufferSize bytes, stopping when the client
 * disconnects. Responses built by this package cannot carry a line break in
 * a header (ADR-005); PHP's header() refuses one as well.
 *
 * ARFA 1.3: P2 (terminal stage: response → client). Spec V4.3.3: final,
 * stateless apart from its buffer size.
 *
 * Preconditions: nothing was sent before emit() (EmitterException 1750);
 * $bufferSize is at least 1 (InvalidConfigurationException 1764).
 *
 * Thread-safety: writes to the one SAPI output of the process.
 *
 * Side effects: sends headers and writes the body to the output.
 *
 * Example:
 *
 *     (new SapiEmitter())->emit($application->handle(ServerRequest::fromGlobals()));
 *
 * References: RFC 9112 §4 (status line); PHP manual, header(); ADR-001.
 *
 * @see https://www.php.net/manual/en/function.header.php
 */
final class SapiEmitter
{
    public function __construct(
        private readonly int $bufferSize = 8192,
    ) {
        if ($bufferSize < 1) {
            throw InvalidConfigurationException::bufferSize();
        }
    }

    public function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw EmitterException::headersAlreadySent($file, $line);
        }

        $code = $response->getStatusCode();
        header(rtrim(sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $code, $response->getReasonPhrase())), true, $code);

        foreach ($response->getHeaders() as $name => $values) {
            foreach (array_values($values) as $index => $value) {
                header($name . ': ' . $value, $index === 0);
            }
        }

        $this->emitBody($response);
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable()) {
            echo $body;

            return;
        }

        while (!$body->eof() && connection_status() === CONNECTION_NORMAL) {
            echo $body->read($this->bufferSize);
        }
    }
}
