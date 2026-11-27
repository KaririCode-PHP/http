<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Enum\StatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Immutable HTTP response (PSR-7 §3.2, RFC 9110 §15).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): all with* methods
 * return new instances. Reason phrases derive from the StatusCode
 * pure enum (RFC 9110 §15.1) when not explicitly overridden.
 * Spec V4.0: final class, immutable (all with* return new instance).
 *
 * @see https://www.php-fig.org/psr/psr-7/#34-psrhttpmessageresponseinterface PSR-7 Response
 */
final class Response implements ResponseInterface
{
    use HasHeaders;

    private int $statusCode;
    private string $reasonPhrase;
    private string $protocolVersion;
    private StreamInterface $body;

    /**
     * @param array<string, string|list<string>> $headers
      *
      * @see https://www.php-fig.org/psr/psr-7/#34-psrhttpmessageresponseinterface PSR-7 Response
     */
    public function __construct(
        int $statusCode = 200,
        string $reasonPhrase = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (StatusCode::phrases()[$statusCode] ?? '');
        $this->protocolVersion = $protocolVersion;
        $this->body = $body ?? Stream::empty();
        $this->setHeaders($headers);
    }

    // ── ResponseInterface ────────────────────────────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (StatusCode::phrases()[$code] ?? '');

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // ── MessageInterface ─────────────────────────────────────────────────

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocolVersion = $version;

        return $new;
    }

    public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        [$new->headers, $new->headerNames] = $new->headersWith($name, self::normalizeHeaderValue($value));

        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;
        [$new->headers, $new->headerNames] = $new->headersWithAdded($name, self::normalizeHeaderValue($value));

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        [$new->headers, $new->headerNames] = $new->headersWithout($name);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }
}

