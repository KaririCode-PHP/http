<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidUriException;
use Psr\Http\Message\UriInterface;

/**
 * A URI (RFC 3986, PSR-7 UriInterface) held as checked components. The
 * scheme and host are lower-cased; the scheme must follow RFC 3986 §3.1 and
 * the host must be a registered name, an IPv4 address or a bracketed IP
 * literal, so a value with "@", "/", "?" or "#" cannot move the authority
 * somewhere else. User info, path, query and fragment are percent-encoded
 * where they hold characters their component does not allow, without
 * encoding an existing escape twice. A with*() call replaces one component
 * and keeps the others as they are; nothing is parsed again.
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new URI). Spec V4.3.3: final
 * PSR-7 value object; the getX() methods and __toString() are the PSR-7
 * contract.
 *
 * Preconditions: the string is something parse_url() accepts
 * (InvalidUriException 1720); components follow the rules above (1721 to
 * 1723).
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $uri = (new Uri('HTTPS://Example.com:443/a b?x=1#top'))->withPath('/docs');
 *     (string) $uri;   // "https://example.com/docs?x=1#top"
 *
 * References: RFC 3986 §3 (components), §2.1 (percent-encoding), §6.2.2.1
 * (case normalisation); PSR-7 UriInterface; ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3
 */
final class Uri implements UriInterface
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];

    private const string SCHEME = '/\A(?:[a-z][a-z0-9+\-.]*)?\z/';

    /** reg-name (unreserved, pct-encoded, sub-delims), or an IP literal in brackets. */
    private const string HOST = '/\A(?:[a-z0-9\-._~!$&\'()*+,;=]|%[0-9a-f]{2})*\z|\A\[(?:[0-9a-f:.]+|v[0-9a-f]+\.[a-z0-9\-._~!$&\'()*+,;=:]+)\]\z/';

    /** Characters outside unreserved and sub-delims, and a "%" that does not start an escape. */
    private const string USER = '/[^A-Za-z0-9\-._~!$&\'()*+,;=%]|%(?![0-9A-Fa-f]{2})/';

    private const string PASSWORD = '/[^A-Za-z0-9\-._~!$&\'()*+,;=:%]|%(?![0-9A-Fa-f]{2})/';

    private const string PATH = '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]|%(?![0-9A-Fa-f]{2})/';

    private const string QUERY = '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%]|%(?![0-9A-Fa-f]{2})/';

    private string $scheme = '';

    private string $userInfo = '';

    private string $host = '';

    private ?int $port = null;

    private string $path = '';

    private string $query = '';

    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            throw InvalidUriException::unparsable();
        }

        $parts += ['scheme' => '', 'user' => '', 'pass' => null, 'host' => '', 'port' => null, 'path' => '', 'query' => '', 'fragment' => ''];
        $this->scheme = self::scheme($parts['scheme']);
        $this->userInfo = self::userInfo($parts['user'], $parts['pass']);
        $this->host = self::host($parts['host']);
        $this->port = self::port($parts['port']);
        $this->path = self::encode(self::PATH, $parts['path']);
        $this->query = self::encode(self::QUERY, $parts['query']);
        $this->fragment = self::encode(self::QUERY, $parts['fragment']);
    }

    #[\Override]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[\Override]
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->userInfo === '' ? $this->host : $this->userInfo . '@' . $this->host;
        $port = $this->getPort();

        return $port === null ? $authority : $authority . ':' . $port;
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    #[\Override]
    public function getHost(): string
    {
        return $this->host;
    }

    #[\Override]
    public function getPort(): ?int
    {
        return $this->port === (self::DEFAULT_PORTS[$this->scheme] ?? null) ? null : $this->port;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getQuery(): string
    {
        return $this->query;
    }

    #[\Override]
    public function getFragment(): string
    {
        return $this->fragment;
    }

    #[\Override]
    public function withScheme(string $scheme): UriInterface
    {
        $new = clone $this;
        $new->scheme = self::scheme($scheme);

        return $new;
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $new = clone $this;
        $new->userInfo = self::userInfo($user, $password);

        return $new;
    }

    #[\Override]
    public function withHost(string $host): UriInterface
    {
        $new = clone $this;
        $new->host = self::host($host);

        return $new;
    }

    #[\Override]
    public function withPort(?int $port): UriInterface
    {
        $new = clone $this;
        $new->port = self::port($port);

        return $new;
    }

    #[\Override]
    public function withPath(string $path): UriInterface
    {
        $new = clone $this;
        $new->path = self::encode(self::PATH, $path);

        return $new;
    }

    #[\Override]
    public function withQuery(string $query): UriInterface
    {
        $new = clone $this;
        $new->query = self::encode(self::QUERY, $query);

        return $new;
    }

    #[\Override]
    public function withFragment(string $fragment): UriInterface
    {
        $new = clone $this;
        $new->fragment = self::encode(self::QUERY, $fragment);

        return $new;
    }

    /**
     * PSR-7 §3.5: a rootless path after an authority gains a "/", and
     * leading slashes without an authority shrink to one, so the path can
     * never be read as an authority.
     */
    #[\Override]
    public function __toString(): string
    {
        $authority = $this->getAuthority();
        $path = $this->path;

        if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        } elseif ($authority === '' && str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        }

        return ($this->scheme === '' ? '' : $this->scheme . ':')
            . ($authority === '' ? '' : '//' . $authority)
            . $path
            . ($this->query === '' ? '' : '?' . $this->query)
            . ($this->fragment === '' ? '' : '#' . $this->fragment);
    }

    private static function scheme(string $scheme): string
    {
        $scheme = strtolower($scheme);

        return preg_match(self::SCHEME, $scheme) === 1 ? $scheme : throw InvalidUriException::scheme();
    }

    private static function userInfo(string $user, ?string $password): string
    {
        $user = self::encode(self::USER, $user);

        return $password === null || $password === '' ? $user : $user . ':' . self::encode(self::PASSWORD, $password);
    }

    private static function host(string $host): string
    {
        $host = strtolower($host);

        return preg_match(self::HOST, $host) === 1 ? $host : throw InvalidUriException::host();
    }

    private static function port(?int $port): ?int
    {
        return $port === null || ($port >= 0 && $port <= 65535) ? $port : throw InvalidUriException::port();
    }

    /**
     * @param non-empty-string $unsafe
     */
    private static function encode(string $unsafe, string $value): string
    {
        return (string) preg_replace_callback($unsafe, static fn(array $match): string => rawurlencode($match[0]), $value);
    }
}
