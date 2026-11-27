<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidMessageException;
use Psr\Http\Message\UriInterface;

/**
 * The request line of a PSR-7 request: method, request target and URI,
 * with the Host header kept in step with the URI as PSR-7 asks. The method
 * must be a token and keeps its case (methods are case-sensitive, RFC 9110
 * §9.1); the request target may not hold whitespace or controls, so a
 * request line built from it cannot gain a second line.
 *
 * ARFA 1.3: P1 (every with*() returns a new request). Spec V4.3.3: trait
 * shared by Request and ServerRequest.
 *
 * Preconditions: the using class also uses MessageState and calls
 * initializeRequestLine() from its constructor after initializeMessage().
 *
 * Thread-safety: requests are immutable once built; share them freely.
 *
 * Side effects: none; with*() clone the request.
 *
 * Example:
 *
 *     $request = new Request('GET', 'https://api.example.com:8443/users?page=2');
 *     $request->getRequestTarget();   // "/users?page=2"
 *     $request->getHeaderLine('Host'); // "api.example.com:8443"
 *
 * References: RFC 9110 §7.2 (Host), §9.1 (methods); RFC 9112 §3.2
 * (request target); PSR-7 RequestInterface; ADR-005.
 *
 * @see https://www.php-fig.org/psr/psr-7/#32-psrhttpmessagerequestinterface
 */
trait RequestLine
{
    private string $method = 'GET';

    private ?string $requestTarget = null;

    private UriInterface $uri;

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        $target = str_starts_with($target, '/') ? $target : '/' . $target;
        $query = $this->uri->getQuery();

        return $query === '' ? $target : $target . '?' . $query;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        if (!HttpGrammar::isRequestTarget($requestTarget)) {
            throw InvalidMessageException::requestTarget();
        }

        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        if (!HttpGrammar::isToken($method)) {
            throw InvalidMessageException::method();
        }

        $new = clone $this;
        $new->method = $method;

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || $this->getHeaderLine('Host') === '') {
            $new->hostFromUri();
        }

        return $new;
    }

    private function initializeRequestLine(string $method, UriInterface|string $uri): void
    {
        if (!HttpGrammar::isToken($method)) {
            throw InvalidMessageException::method();
        }

        $this->method = $method;
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;

        if (!$this->hasHeader('Host')) {
            $this->hostFromUri();
        }
    }

    /** Sets Host from the URI, first among the headers; a URI without host leaves it alone. */
    private function hostFromUri(): void
    {
        $host = $this->uri->getHost();

        if ($host === '') {
            return;
        }

        $port = $this->uri->getPort();
        $this->dropHeader('Host');
        $this->headerNames = ['host' => 'Host'] + $this->headerNames;
        $this->headers = ['Host' => [$port === null ? $host : $host . ':' . $port]] + $this->headers;
    }
}
