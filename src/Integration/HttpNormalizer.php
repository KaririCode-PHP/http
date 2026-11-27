<?php

declare(strict_types=1);

namespace KaririCode\Http\Integration;

use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bridges PSR-7 ServerRequest into the Router's string-based matching.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): implements the
 * {@see RequestNormalizer} contract from kariricode/router. This class
 * lives in kariricode/http (not in Router) so the Router package has
 * zero knowledge of PSR-7 or any HTTP types.
 *
 * Dependency direction: Http → Router (never reversed).
 * Adding a new protocol (gRPC, WebSocket, MQTT) requires a new
 * normalizer in that protocol's package — zero changes here or
 * in the Router.
 *
 * @see \KaririCode\Router\Contract\RequestNormalizer
 * @see \KaririCode\Router\Core\NormalizedRequest
 * Spec V4.0: final class, zero state, pure transformation.
 *
 * @see https://www.php-fig.org/psr/psr-7/#33-psrhttpmessageserverrequestinterface PSR-7 ServerRequest
 */
final class HttpNormalizer implements RequestNormalizer
{
    public function canNormalize(object $request): bool
    {
        return $request instanceof ServerRequestInterface;
    }

    public function normalize(object $request): NormalizedRequest
    {
        assert($request instanceof ServerRequestInterface);

        return new NormalizedRequest(
            method: strtoupper($request->getMethod()),
            uri: $request->getUri()->getPath() ?: '/',
            original: $request,
        );
    }
}

