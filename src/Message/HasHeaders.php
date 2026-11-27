<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

/**
 * Case-insensitive header storage and manipulation.
 *
 * ARFA 1.3 P1: used by immutable Message types — clone safety
 * is guaranteed because PHP arrays are copy-on-write value types.
 * Spec V4.0: trait (shared implementation for Request/Response/ServerRequest).
 *
 * RFC 9110 §5.1: HTTP field names are case-insensitive. This trait
 * maintains a dual-map (original-case → values, lowercase → original)
 * so headers preserve caller casing but match case-insensitively.
 *
 * Shared via `use` across Request, Response, and ServerRequest.
 * Internal arrays are cloned correctly because PHP clone performs
 * a shallow copy of arrays (which are copy-on-write value types).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
 */
trait HasHeaders
{
    /** @var array<string, list<string>> Normalized name → values */
    private array $headers = [];

    /** @var array<string, string> Lowercase → original case */
    private array $headerNames = [];

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);
        if (!isset($this->headerNames[$normalized])) {
            return [];
        }

        return $this->headers[$this->headerNames[$normalized]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @param array<string, string|list<string>> $headers
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
     */
    protected function setHeaders(array $headers): void
    {
        $this->headers = [];
        $this->headerNames = [];

        foreach ($headers as $name => $value) {
            $values = is_array($value) ? $value : [$value];
            $normalized = strtolower($name);

            if (isset($this->headerNames[$normalized])) {
                $existing = $this->headerNames[$normalized];
                $this->headers[$existing] = array_merge($this->headers[$existing], $values);
            } else {
                $this->headerNames[$normalized] = $name;
                $this->headers[$name] = $values;
            }
        }
    }

    /**
     * @return array{array<string, list<string>>, array<string, string>}
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
     */
    protected function headersWith(string $name, array $values): array
    {
        $headers = $this->headers;
        $names = $this->headerNames;
        $normalized = strtolower($name);

        if (isset($names[$normalized])) {
            unset($headers[$names[$normalized]]);
        }

        $names[$normalized] = $name;
        $headers[$name] = $values;

        return [$headers, $names];
    }

    /**
     * @return array{array<string, list<string>>, array<string, string>}
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
     */
    protected function headersWithAdded(string $name, array $values): array
    {
        $headers = $this->headers;
        $names = $this->headerNames;
        $normalized = strtolower($name);

        if (isset($names[$normalized])) {
            $existing = $names[$normalized];
            $headers[$existing] = array_merge($headers[$existing], $values);
        } else {
            $names[$normalized] = $name;
            $headers[$name] = $values;
        }

        return [$headers, $names];
    }

    /**
     * @return array{array<string, list<string>>, array<string, string>}
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2 HTTP/1.1 Header Fields
     */
    protected function headersWithout(string $name): array
    {
        $headers = $this->headers;
        $names = $this->headerNames;
        $normalized = strtolower($name);

        if (isset($names[$normalized])) {
            unset($headers[$names[$normalized]], $names[$normalized]);
        }

        return [$headers, $names];
    }

    protected static function normalizeHeaderValue(string|array $value): array
    {
        if (is_string($value)) {
            return [trim($value)];
        }

        return array_map(trim(...), $value);
    }
}

