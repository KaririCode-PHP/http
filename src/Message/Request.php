<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * An outgoing HTTP request (PSR-7 RequestInterface), as a client sends it.
 * Method, URI, headers, body and protocol version are checked when the
 * request is built and on every with*() call (MessageState, RequestLine);
 * the Host header follows the URI unless the caller set one.
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new request). Spec V4.3.3:
 * final PSR-7 message; the getX() methods are the PSR-7 contract.
 *
 * Preconditions: $method is a token, $headers maps field names to a string
 * or a list of strings without control characters, $protocolVersion is
 * like "1.1" (InvalidMessageException otherwise).
 *
 * Thread-safety: immutable once built; the body stream is shared by the
 * copies with*() makes, as PSR-7 intends.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $request = new Request('POST', 'https://api.example.com/orders', ['Content-Type' => 'application/json'], Stream::fromString($json));
 *
 * References: PSR-7 RequestInterface; RFC 9110; ADR-001, ADR-005.
 *
 * @see https://www.php-fig.org/psr/psr-7/#32-psrhttpmessagerequestinterface
 */
final class Request implements RequestInterface
{
    use MessageState;
    use RequestLine;

    /**
     * @param array<array-key, mixed> $headers field name => string or list of strings
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        $this->initializeMessage($protocolVersion, $headers, $body);
        $this->initializeRequestLine($method, $uri);
    }
}
