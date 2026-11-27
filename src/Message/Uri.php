<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\UriInterface;

/**
 * RFC 3986 compliant URI value object.
 *
 * ARFA 1.3 P1: readonly class — all with* methods return new instances.
 * Components are normalized per RFC 3986 §6.2.2: scheme and host
 * lowercased, default ports (RFC 3986 §3.2.3) omitted from authority,
 * path/query/fragment percent-encoded for unreserved characters.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986
 * Spec V4.0: final readonly class (PHP 8.4 readonly + PSR-7 immutability).
 */
final readonly class Uri implements UriInterface
{
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
        'ftp' => 21,
        'ssh' => 22,
    ];

    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            $this->scheme = '';
            $this->userInfo = '';
            $this->host = '';
            $this->port = null;
            $this->path = '';
            $this->query = '';
            $this->fragment = '';
            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI: {$uri}");
        }

        $this->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $this->port = isset($parts['port']) ? self::filterPort($parts['port']) : null;
        $this->path = isset($parts['path']) ? self::filterPath($parts['path']) : '';
        $this->query = isset($parts['query']) ? self::filterQueryOrFragment($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? self::filterQueryOrFragment($parts['fragment']) : '';

        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? null;
        $this->userInfo = $pass !== null ? "{$user}:{$pass}" : $user;
    }

    // ── PSR-7 Accessors ──────────────────────────────────────────────────

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        $authority = $this->host;
        if ($authority === '') {
            return '';
        }

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null && !self::isDefaultPort($this->scheme, $this->port)) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return self::isDefaultPort($this->scheme, $this->port) ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    // ── PSR-7 Immutable Setters ──────────────────────────────────────────

    public function withScheme(string $scheme): UriInterface
    {
        $normalized = strtolower($scheme);
        if ($normalized === $this->scheme) {
            return $this;
        }

        return new self($this->buildUri(scheme: $normalized));
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $info = $password !== null && $password !== '' ? "{$user}:{$password}" : $user;
        if ($info === $this->userInfo) {
            return $this;
        }

        return new self($this->buildUri(userInfo: $info));
    }

    public function withHost(string $host): UriInterface
    {
        $normalized = strtolower($host);
        if ($normalized === $this->host) {
            return $this;
        }

        return new self($this->buildUri(host: $normalized));
    }

    public function withPort(?int $port): UriInterface
    {
        $filtered = $port !== null ? self::filterPort($port) : null;
        if ($filtered === $this->port) {
            return $this;
        }

        return new self($this->buildUri(port: $filtered));
    }

    public function withPath(string $path): UriInterface
    {
        $filtered = self::filterPath($path);
        if ($filtered === $this->path) {
            return $this;
        }

        return new self($this->buildUri(path: $filtered));
    }

    public function withQuery(string $query): UriInterface
    {
        $filtered = self::filterQueryOrFragment($query);
        if ($filtered === $this->query) {
            return $this;
        }

        return new self($this->buildUri(query: $filtered));
    }

    public function withFragment(string $fragment): UriInterface
    {
        $filtered = self::filterQueryOrFragment($fragment);
        if ($filtered === $this->fragment) {
            return $this;
        }

        return new self($this->buildUri(fragment: $filtered));
    }

    public function __toString(): string
    {
        return self::composeUri(
            $this->scheme,
            $this->getAuthority(),
            $this->path,
            $this->query,
            $this->fragment,
        );
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function buildUri(
        ?string $scheme = null,
        ?string $userInfo = null,
        ?string $host = null,
        ?int $port = -1,
        ?string $path = null,
        ?string $query = null,
        ?string $fragment = null,
    ): string {
        $resolvedScheme = $scheme ?? $this->scheme;
        $resolvedUserInfo = $userInfo ?? $this->userInfo;
        $resolvedHost = $host ?? $this->host;
        $resolvedPort = $port === -1 ? $this->port : $port;
        $resolvedPath = $path ?? $this->path;
        $resolvedQuery = $query ?? $this->query;
        $resolvedFragment = $fragment ?? $this->fragment;

        $authority = $resolvedHost;
        if ($authority !== '' && $resolvedUserInfo !== '') {
            $authority = $resolvedUserInfo . '@' . $authority;
        }
        if ($authority !== '' && $resolvedPort !== null && !self::isDefaultPort($resolvedScheme, $resolvedPort)) {
            $authority .= ':' . $resolvedPort;
        }

        return self::composeUri($resolvedScheme, $authority, $resolvedPath, $resolvedQuery, $resolvedFragment);
    }

    /**
     * RFC 3986 §5.3 — Component Recomposition.
     */
    private static function composeUri(
        string $scheme,
        string $authority,
        string $path,
        string $query,
        string $fragment,
    ): string {
        $uri = '';

        if ($scheme !== '') {
            $uri .= $scheme . ':';
        }

        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        if ($path !== '') {
            if ($authority !== '' && !str_starts_with($path, '/')) {
                $path = '/' . $path;
            } elseif ($authority === '' && str_starts_with($path, '//')) {
                $path = '/' . ltrim($path, '/');
            }
        }

        $uri .= $path;

        if ($query !== '') {
            $uri .= '?' . $query;
        }

        if ($fragment !== '') {
            $uri .= '#' . $fragment;
        }

        return $uri;
    }

    private static function filterPort(int $port): int
    {
        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid port: {$port}. Must be 0–65535");
        }

        return $port;
    }

    private static function filterPath(string $path): string
    {
        return preg_replace_callback(
            '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/%]/',
            static fn (array $m) => rawurlencode($m[0]),
            $path,
        );
    }

    private static function filterQueryOrFragment(string $value): string
    {
        return preg_replace_callback(
            '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/?%]/',
            static fn (array $m) => rawurlencode($m[0]),
            $value,
        );
    }

    private static function isDefaultPort(string $scheme, ?int $port): bool
    {
        if ($port === null) {
            return false;
        }

        return (self::DEFAULT_PORTS[$scheme] ?? null) === $port;
    }
}
