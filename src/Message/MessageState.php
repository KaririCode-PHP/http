<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidMessageException;
use Psr\Http\Message\StreamInterface;

/**
 * The part of a PSR-7 message that requests and responses share: protocol
 * version, header fields and body. Field names match case-insensitively
 * (RFC 9110 §5.1) and keep the case they were given. Every name must be a
 * token and every value free of CR, LF and other control characters
 * (RFC 9110 §5.5), so no value can end its header line and start another:
 * a response or request built here cannot be split. Leading and trailing
 * spaces and tabs are trimmed, as the field-value grammar asks.
 *
 * ARFA 1.3: P1 (every with*() returns a new message). Spec V4.3.3: trait
 * shared by Request, ServerRequest and Response.
 *
 * Preconditions: the using class calls initializeMessage() from its
 * constructor before any other method.
 *
 * Thread-safety: messages are immutable once built; share them freely.
 *
 * Side effects: none; with*() clone the message.
 *
 * Example:
 *
 *     $response = (new Response(200))->withHeader('Content-Type', 'application/json');
 *     $response->getHeaderLine('content-type');  // "application/json"
 *
 * References: RFC 9110 §5.1–§5.5; PSR-7 MessageInterface; ADR-005.
 *
 * @see https://www.php-fig.org/psr/psr-7/#31-psrhttpmessagemessageinterface
 */
trait MessageState
{
    private string $protocolVersion = '1.1';

    /** @var array<string, non-empty-list<string>> field name as given => values */
    private array $headers = [];

    /** @var array<string, string> lower-case field name => name as given */
    private array $headerNames = [];

    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        if (!HttpGrammar::isProtocolVersion($version)) {
            throw InvalidMessageException::protocolVersion();
        }

        $new = clone $this;
        $new->protocolVersion = $version;

        return $new;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $given = $this->headerNames[strtolower($name)] ?? null;

        return $given === null ? [] : $this->headers[$given];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @param string|int|float|array<string|int|float> $value numbers are kept as text
     */
    public function withHeader(string $name, $value): static
    {
        $values = self::headerValues($name, $value);
        $new = clone $this;
        $new->putHeader($name, $values, true);

        return $new;
    }

    /**
     * @param string|int|float|array<string|int|float> $value numbers are kept as text
     */
    public function withAddedHeader(string $name, $value): static
    {
        $values = self::headerValues($name, $value);
        $new = clone $this;
        $new->putHeader($name, $values, false);

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        $new->dropHeader($name);

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

    /**
     * @param array<array-key, mixed> $headers field name => string or list of strings
     */
    private function initializeMessage(string $protocolVersion, array $headers, ?StreamInterface $body): void
    {
        if (!HttpGrammar::isProtocolVersion($protocolVersion)) {
            throw InvalidMessageException::protocolVersion();
        }

        $this->protocolVersion = $protocolVersion;
        $this->body = $body ?? Stream::empty();

        foreach (array_keys($headers) as $name) {
            $text = (string) $name;
            $this->putHeader($text, self::headerValues($text, $headers[$name]), false);
        }
    }

    /**
     * @param non-empty-list<string> $values
     */
    private function putHeader(string $name, array $values, bool $replace): void
    {
        $key = strtolower($name);
        $given = $this->headerNames[$key] ?? null;

        if ($given !== null && !$replace) {
            $this->headers[$given] = [...$this->headers[$given], ...$values];

            return;
        }

        $this->dropHeader($name);
        $this->headerNames[$key] = $name;
        $this->headers[$name] = $values;
    }

    private function dropHeader(string $name): void
    {
        $key = strtolower($name);

        if (isset($this->headerNames[$key])) {
            unset($this->headers[$this->headerNames[$key]], $this->headerNames[$key]);
        }
    }

    /**
     * The values of a field, checked and trimmed of spaces and tabs.
     *
     * @return non-empty-list<string>
     */
    private static function headerValues(string $name, mixed $value): array
    {
        if (!HttpGrammar::isToken($name)) {
            throw InvalidMessageException::headerName();
        }

        $values = self::acceptableValues($value);

        return $values ?? throw InvalidMessageException::headerValue($name);
    }

    /**
     * @return non-empty-list<string>|null null when a value is missing, of another type or not a field value
     */
    private static function acceptableValues(mixed $value): ?array
    {
        $items = is_array($value) ? $value : [$value];
        $values = [];

        foreach (array_keys($items) as $key) {
            $text = self::fieldValue($items[$key]);

            if ($text === null) {
                return null;
            }

            $values[] = $text;
        }

        return $values === [] ? null : $values;
    }

    /** A string or number, trimmed of spaces and tabs, when it is a valid field value. */
    private static function fieldValue(mixed $item): ?string
    {
        if (!is_string($item) && !is_int($item) && !is_float($item)) {
            return null;
        }

        $text = trim((string) $item, " \t");

        return HttpGrammar::isFieldValue($text) ? $text : null;
    }
}
