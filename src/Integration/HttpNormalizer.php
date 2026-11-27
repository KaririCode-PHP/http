<?php

declare(strict_types=1);

namespace KaririCode\Http\Integration;

use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lets kariricode/router match PSR-7 server requests: the method and the
 * path become the two strings the router matches on, and the request
 * travels along in NormalizedRequest. The method is upper-cased, as the
 * router's contract asks and as it stores the methods of its routes; an
 * empty path is "/".
 *
 * ARFA 1.3: P4 (protocol agnosticism: the router knows no PSR-7). Spec
 * V4.3.3: final, stateless; the `object` parameters are the router's
 * accepted deviation (kariricode/router ADR-002).
 *
 * Preconditions: normalize() is called for a request canNormalize()
 * accepted; anything else throws InvalidMessageException 1711.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $normalized = (new HttpNormalizer())->normalize($request);
 *     $result = $routes->match($normalized->method, $normalized->uri);
 *
 * References: kariricode/router RequestNormalizer and NormalizedRequest;
 * ADR-002.
 *
 * @see https://www.php-fig.org/psr/psr-7/
 */
final class HttpNormalizer implements RequestNormalizer
{
    #[\Override]
    public function canNormalize(object $request): bool
    {
        return $request instanceof ServerRequestInterface;
    }

    #[\Override]
    public function normalize(object $request): NormalizedRequest
    {
        if (!$request instanceof ServerRequestInterface) {
            throw InvalidMessageException::notAServerRequest();
        }

        $path = $request->getUri()->getPath();

        return new NormalizedRequest(method: strtoupper($request->getMethod()), uri: $path === '' ? '/' : $path, original: $request);
    }
}
