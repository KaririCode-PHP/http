<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Immutable outgoing HTTP request (PSR-7 §3.1, RFC 9110 §9).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): every with* method
 * clones internal state and returns a new instance. Original is
 * never mutated, ensuring thread-safety in async runtimes
 * (Swoole, RoadRunner, ReactPHP).
 *
 * Auto-sets Host header from URI when not explicitly provided,
 * per PSR-7 §3.1.2.
 * Spec V4.0: final class, immutable (all with* return new instance).
 *
 * @see https://www.php-fig.org/psr/psr-7/#32-psrhttpmessagerequestinterface PSR-7 Request
 */
final class Request implements RequestInterface
{
    use HasHeaders;

    private string $method;
    private ?string $requestTarget = null;
    private UriInterface $uri;
    private string $protocolVersion;
    private StreamInterface $body;

    /**
     * @param array<string, string|list<string>> $headers
      *
      * @see https://www.php-fig.org/psr/psr-7/#32-psrhttpmessagerequestinterface PSR-7 Request
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        $this->method = strtoupper($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->protocolVersion = $protocolVersion;
        $this->body = $body ?? Stream::empty();
        $this->setHeaders($headers);

        // Auto-set Host header from URI if not provided
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();
            $port = $this->uri->getPort();
            if ($port !== null) {
                $host .= ':' . $port;
            }
            [$this->headers, $this->headerNames] = $this->headersWith('Host', [$host]);
        }
    }

    // ── RequestInterface ─────────────────────────────────────────────────

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath() ?: '/';
        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !$this->hasHeader('Host')) {
            $host = $uri->getHost();
            if ($host !== '') {
                $port = $uri->getPort();
                if ($port !== null) {
                    $host .= ':' . $port;
                }
                [$new->headers, $new->headerNames] = $new->headersWith('Host', [$host]);
            }
        }

        return $new;
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

