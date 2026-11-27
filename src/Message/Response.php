<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Enum\StatusCode;
use KaririCode\Http\Exception\InvalidMessageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * An HTTP response (PSR-7 ResponseInterface). The status code must be
 * between 100 and 599 and the reason phrase free of CR, LF and other
 * controls, so the status line cannot gain a header of its own; an empty
 * phrase takes the IANA phrase of the code. Headers and body follow
 * MessageState.
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new response). Spec V4.3.3:
 * final PSR-7 message; the getX() methods are the PSR-7 contract.
 *
 * Preconditions: as above (InvalidMessageException, 1705 and 1706), plus
 * the header and version rules of MessageState.
 *
 * Thread-safety: immutable once built; the body stream is shared by the
 * copies with*() makes, as PSR-7 intends.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $response = (new Response(201))
 *         ->withHeader('Location', '/orders/42')
 *         ->withBody(Stream::fromString('{"id":42}'));
 *
 * References: RFC 9110 §15 (status codes); RFC 9112 §4 (status line);
 * PSR-7 ResponseInterface; ADR-005.
 *
 * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageresponseinterface
 */
final class Response implements ResponseInterface
{
    use MessageState;

    private int $statusCode;

    private string $reasonPhrase;

    /**
     * @param array<array-key, mixed> $headers field name => string or list of strings
     */
    public function __construct(
        int $statusCode = 200,
        string $reasonPhrase = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        $this->initializeMessage($protocolVersion, $headers, $body);
        [$this->statusCode, $this->reasonPhrase] = self::status($statusCode, $reasonPhrase);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        [$new->statusCode, $new->reasonPhrase] = self::status($code, $reasonPhrase);

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return array{int, string}
     */
    private static function status(int $code, string $reasonPhrase): array
    {
        if ($code < 100 || $code > 599) {
            throw InvalidMessageException::statusCode();
        }

        if (!HttpGrammar::isReasonPhrase($reasonPhrase)) {
            throw InvalidMessageException::reasonPhrase();
        }

        return [$code, $reasonPhrase !== '' ? $reasonPhrase : (StatusCode::tryFromCode($code)?->reasonPhrase() ?? '')];
    }
}
