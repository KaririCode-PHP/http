<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use KaririCode\Http\Exception\InvalidMessageException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * A request as the server received it (PSR-7 ServerRequestInterface): the
 * request line and headers, plus server parameters, cookies, query, parsed
 * body, uploaded files and attributes. fromGlobals() builds it from PHP's
 * superglobals and treats what the client sent as untrusted: a Host header
 * that is not host[:port] is ignored for SERVER_NAME, so "a.com@b.com" or
 * "a.com/x" cannot change the host of the URI; header fields that break the
 * HTTP grammar are skipped instead of failing the request; the scheme comes
 * from the server (HTTPS, REQUEST_SCHEME), never from X-Forwarded-*
 * headers a client can set.
 *
 * ARFA 1.3: P1 (immutable; with*() returns a new request). Spec V4.3.3:
 * final PSR-7 message; the getX() methods are the PSR-7 contract.
 *
 * Preconditions: as Request; withUploadedFiles() takes a tree of
 * UploadedFileInterface (1707) and withParsedBody() an array, an object or
 * null (1708).
 *
 * Thread-safety: immutable once built; the body stream is shared by the
 * copies with*() makes, as PSR-7 intends.
 *
 * Side effects: fromGlobals() reads the superglobals and opens php://input.
 *
 * Example:
 *
 *     $request = ServerRequest::fromGlobals();
 *     $page = (int) ($request->getQueryParams()['page'] ?? 1);
 *
 * References: PSR-7 ServerRequestInterface; RFC 9112 §3.2 (request target),
 * §3.2.2 and RFC 9110 §7.2 (Host); RFC 3986 §3.2.2; ADR-005.
 *
 * @see https://www.php-fig.org/psr/psr-7/#321-psrhttpmessageserverrequestinterface
 */
final class ServerRequest implements ServerRequestInterface
{
    use MessageState;
    use RequestLine;

    /** host [":" port], RFC 9110 §7.2: a registered name, an IPv4 address or a bracketed literal. */
    private const string HOST_HEADER = '/\A(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9\-._~!$&\'()*+,;=%]+)(?::(\d{1,5}))?\z/';

    /** @var array<array-key, mixed> */
    private array $cookieParams = [];

    /** @var array<array-key, mixed> */
    private array $queryParams = [];

    /** @var array<array-key, mixed> */
    private array $uploadedFiles = [];

    /** @var array<array-key, mixed>|object|null */
    private array|object|null $parsedBody = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<array-key, mixed> $headers      field name => string or list of strings
     * @param array<array-key, mixed> $serverParams usually $_SERVER
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        private readonly array $serverParams = [],
    ) {
        $this->initializeMessage($protocolVersion, $headers, $body);
        $this->initializeRequestLine($method, $uri);
    }

    public static function fromGlobals(): self
    {
        $input = Silently::run(static fn(): mixed => fopen('php://input', 'r'));

        return self::fromServer($_SERVER, is_resource($input) ? new Stream($input) : null)
            ->withCookieParams($_COOKIE)
            ->withQueryParams($_GET)
            ->withParsedBody($_POST === [] ? null : $_POST)
            ->withUploadedFiles(UploadedFile::treeFromGlobals($_FILES));
    }

    /**
     * The request line, headers and URI from a $_SERVER-shaped array.
     *
     * @param array<array-key, mixed> $server
     */
    public static function fromServer(array $server, ?StreamInterface $body = null): self
    {
        $method = self::text($server['REQUEST_METHOD'] ?? null);
        [$path, $query, $target] = self::requestUri(self::text($server['REQUEST_URI'] ?? null));
        $uri = self::authority($server)->withPath($path)->withQuery($query);
        $request = new self($method === '' ? 'GET' : $method, $uri, self::headersFrom($server), $body, self::protocolVersion($server), $server);

        return $target === null ? $request : $request->withRequestTarget($target);
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<array-key, mixed> $cookies
     */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->cookieParams = $cookies;

        return $new;
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->queryParams = $query;

        return $new;
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    #[\Override]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        self::assertFileTree($uploadedFiles);
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    /**
     * @return array<array-key, mixed>|object|null
     */
    #[\Override]
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * PSR-7 types $data in a docblock only; a caller without static
     * analysis can still pass a scalar, so it is checked here.
     *
     * @param mixed $data an array, an object or null
     *
     * @psalm-suppress DocblockTypeContradiction
     */
    #[\Override]
    public function withParsedBody($data): static
    {
        if (!is_array($data) && !is_object($data) && $data !== null) {
            throw InvalidMessageException::parsedBody();
        }

        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param mixed $default
     */
    #[\Override]
    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @param mixed $value
     */
    #[\Override]
    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    #[\Override]
    public function withoutAttribute(string $name): static
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }

    /**
     * @param array<array-key, mixed> $files
     */
    private static function assertFileTree(array $files): void
    {
        foreach (array_keys($files) as $key) {
            self::assertFileNode($files[$key]);
        }
    }

    private static function assertFileNode(mixed $node): void
    {
        if (is_array($node)) {
            self::assertFileTree($node);
        } elseif (!$node instanceof UploadedFileInterface) {
            throw InvalidMessageException::uploadedFiles();
        }
    }

    /**
     * Scheme, host and port: the Host header when it is host[:port], else
     * SERVER_NAME and SERVER_PORT.
     *
     * @param array<array-key, mixed> $server
     */
    private static function authority(array $server): UriInterface
    {
        $uri = (new Uri())->withScheme(self::isSecure($server) ? 'https' : 'http');
        $host = self::hostAndPort(self::text($server['HTTP_HOST'] ?? null))
            ?? self::hostAndPort(self::text($server['SERVER_NAME'] ?? null), self::text($server['SERVER_PORT'] ?? null));

        return $host === null ? $uri : $uri->withHost($host[0])->withPort($host[1]);
    }

    /**
     * HTTPS (any value but "off") or REQUEST_SCHEME, both set by the server.
     *
     * @param array<array-key, mixed> $server
     */
    private static function isSecure(array $server): bool
    {
        $https = strtolower(self::text($server['HTTPS'] ?? null));

        return ($https !== '' && $https !== 'off') || strtolower(self::text($server['REQUEST_SCHEME'] ?? null)) === 'https';
    }

    /**
     * host [":" port] when it is one. A port in the value must be at most
     * 65535; without one, $defaultPort is used when it is a usable port.
     *
     * @return array{string, int|null}|null
     */
    private static function hostAndPort(string $value, string $defaultPort = ''): ?array
    {
        if (preg_match(self::HOST_HEADER, $value, $match) !== 1) {
            return null;
        }

        [$host, $given] = [$match[1], $match[2] ?? ''];

        if ($given !== '') {
            $port = (int) $given;

            return $port <= 65535 ? [$host, $port] : null;
        }

        $port = ctype_digit($defaultPort) ? (int) $defaultPort : 65536;

        return [$host, $port <= 65535 ? $port : null];
    }

    /**
     * Path, query and, for the asterisk form, the explicit request target.
     *
     * @return array{string, string, string|null}
     */
    private static function requestUri(string $requestUri): array
    {
        if ($requestUri === '*') {
            return ['', '', '*'];
        }

        if (preg_match('#\A[A-Za-z][A-Za-z0-9+\-.]*://#', $requestUri) === 1) {
            $parts = parse_url($requestUri);

            return $parts === false ? ['/', '', null] : [$parts['path'] ?? '/', $parts['query'] ?? '', null];
        }

        if (!str_starts_with($requestUri, '/')) {
            return ['/', '', null];
        }

        $query = strpos($requestUri, '?');

        return $query === false ? [$requestUri, '', null] : [substr($requestUri, 0, $query), substr($requestUri, $query + 1), null];
    }

    /**
     * The header fields PHP passed as HTTP_* (and CONTENT_TYPE,
     * CONTENT_LENGTH), skipping those that break the grammar.
     *
     * @param array<array-key, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFrom(array $server): array
    {
        $headers = [];

        foreach (array_keys($server) as $key) {
            $name = self::headerName((string) $key);
            $value = self::text($server[$key]);

            if ($name !== null && $value !== '' && HttpGrammar::isToken($name) && HttpGrammar::isFieldValue($value)) {
                $headers[$name] = $value;
            }
        }

        $authorization = self::text($server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

        if (!isset($headers['Authorization']) && $authorization !== '' && HttpGrammar::isFieldValue($authorization)) {
            $headers['Authorization'] = $authorization;
        }

        return $headers;
    }

    /** HTTP_X_REQUEST_ID → X-Request-Id; null for keys that are not header fields. */
    private static function headerName(string $key): ?string
    {
        $name = match (true) {
            str_starts_with($key, 'HTTP_') => substr($key, 5),
            $key === 'CONTENT_TYPE', $key === 'CONTENT_LENGTH' => $key,
            default => null,
        };

        return $name === null || $name === '' ? null : ucwords(strtolower(str_replace('_', '-', $name)), '-');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function protocolVersion(array $server): string
    {
        $protocol = self::text($server['SERVER_PROTOCOL'] ?? null);

        return preg_match('#\AHTTP/(\d(?:\.\d)?)\z#', $protocol, $match) === 1 ? $match[1] : '1.1';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
