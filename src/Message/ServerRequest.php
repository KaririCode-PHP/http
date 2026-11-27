<?php

declare(strict_types=1);

namespace KaririCode\Http\Message;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Immutable server-side HTTP request (PSR-7 §3.3, RFC 9110 §9).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): all with* methods
 * return new instances. Extends the base request contract with
 * server-specific data: parsed body, cookies, query params,
 * uploaded files, and application-level attributes.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): the static fromGlobals()
 * factory only covers PHP SAPI. For Swoole/RoadRunner/ReactPHP,
 * construct directly from the runtime's request object.
 * Spec V4.0: final class, immutable (all with* return new instance).
 *
 * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
 */
final class ServerRequest implements ServerRequestInterface
{
    use HasHeaders;

    private string $method;
    private ?string $requestTarget = null;
    private UriInterface $uri;
    private string $protocolVersion;
    private StreamInterface $body;

    /** @var array<string, mixed> */
    private array $serverParams;
    /** @var array<string, string> */
    private array $cookieParams;
    /** @var array<string, mixed> */
    private array $queryParams;
    /** @var array<string, UploadedFileInterface> */
    private array $uploadedFiles;
    private array|object|null $parsedBody;
    /** @var array<string, mixed> */
    private array $attributes;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed>               $serverParams
      *
      * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        array $serverParams = [],
    ) {
        $this->method = strtoupper($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->protocolVersion = $protocolVersion;
        $this->body = $body ?? Stream::empty();
        $this->serverParams = $serverParams;
        $this->cookieParams = [];
        $this->queryParams = [];
        $this->uploadedFiles = [];
        $this->parsedBody = null;
        $this->attributes = [];
        $this->setHeaders($headers);

        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();
            $port = $this->uri->getPort();
            if ($port !== null) {
                $host .= ':' . $port;
            }
            [$this->headers, $this->headerNames] = $this->headersWith('Host', [$host]);
        }
    }

    /**
     * Builds a ServerRequest from PHP superglobals.
      *
      * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = self::buildUriFromGlobals($_SERVER);
        $headers = function_exists('getallheaders') ? getallheaders() : self::headersFromServer($_SERVER);
        $body = new Stream(fopen('php://input', 'r'));
        $protocol = isset($_SERVER['SERVER_PROTOCOL'])
            ? str_replace('HTTP/', '', $_SERVER['SERVER_PROTOCOL'])
            : '1.1';

        $request = new self($method, $uri, $headers, $body, $protocol, $_SERVER);
        $request->cookieParams = $_COOKIE;
        $request->queryParams = $_GET;
        $request->uploadedFiles = self::normalizeFiles($_FILES);
        $request->parsedBody = $_POST ?: null;

        return $request;
    }

    // ── ServerRequestInterface ───────────────────────────────────────────

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->cookieParams = $cookies;

        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new = clone $this;
        $new->queryParams = $query;

        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
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

    public function withRequestTarget(string $requestTarget): static
    {
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
        $new = clone $this;
        $new->method = strtoupper($method);

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

    // ── Globals Helpers ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $server
      *
      * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
     */
    private static function buildUriFromGlobals(array $server): UriInterface
    {
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
        $port = isset($server['SERVER_PORT']) ? (int) $server['SERVER_PORT'] : null;
        $path = $server['REQUEST_URI'] ?? '/';
        $query = '';

        if (($qPos = strpos($path, '?')) !== false) {
            $query = substr($path, $qPos + 1);
            $path = substr($path, 0, $qPos);
        }

        $uri = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $uri .= ':' . $port;
        }
        $uri .= $path;
        if ($query !== '') {
            $uri .= '?' . $query;
        }

        return new Uri($uri);
    }

    /**
     * @param array<string, string> $server
     * @return array<string, string>
      *
      * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $files $_FILES superglobal
     * @return array<string, UploadedFileInterface>
      *
      * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                if (is_array($value['tmp_name'])) {
                    $normalized[$key] = self::normalizeNestedFiles($value);
                } else {
                    $normalized[$key] = new UploadedFile(
                        $value['tmp_name'],
                        (int) ($value['size'] ?? 0),
                        (int) ($value['error'] ?? UPLOAD_ERR_OK),
                        $value['name'] ?? null,
                        $value['type'] ?? null,
                    );
                }
            }
        }

        return $normalized;
    }

    private static function normalizeNestedFiles(array $files): array
    {
        $normalized = [];
        foreach (array_keys($files['tmp_name']) as $key) {
            $normalized[$key] = new UploadedFile(
                $files['tmp_name'][$key],
                (int) ($files['size'][$key] ?? 0),
                (int) ($files['error'][$key] ?? UPLOAD_ERR_OK),
                $files['name'][$key] ?? null,
                $files['type'][$key] ?? null,
            );
        }

        return $normalized;
    }
}

