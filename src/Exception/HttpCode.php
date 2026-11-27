<?php

declare(strict_types=1);

namespace KaririCode\Http\Exception;

use KaririCode\Exception\CodeBlock;
use KaririCode\Exception\ErrorCode;

/**
 * The error codes of kariricode/http: block 1700–1799 of the ecosystem
 * registry under the prefix HTTP, so a log line that starts with
 * `[HTTP_1701]` names this package and one failure. They identify the
 * failure inside this package; the HTTP status a response carries is a
 * separate thing. The layout:
 *
 *     1700–1719  message arguments (headers, method, version, target, status)
 *     1720–1729  URIs
 *     1730–1739  streams
 *     1740–1749  uploaded files
 *     1750–1759  the SAPI emitter
 *     1760–1779  configuration (CORS, trusted proxies, buffers, TLS)
 *
 * Preconditions: a code is stable API (registry rule 4). A case is never
 * renumbered and a retired value is never reused.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $response->withHeader('X-Name', "a\r\nSet-Cookie: x");
 *     } catch (InvalidMessageException $e) {
 *         assert($e->errorCode === HttpCode::InvalidHeaderValue && $e->getCode() === 1701);
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.4; kariricode/exception ErrorCode
 * and CODE-BLOCKS.md; ADR-003.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/CODE-BLOCKS.md
 */
enum HttpCode: int implements ErrorCode
{
    case InvalidHeaderName = 1700;
    case InvalidHeaderValue = 1701;
    case InvalidMethod = 1702;
    case InvalidProtocolVersion = 1703;
    case InvalidRequestTarget = 1704;
    case InvalidStatusCode = 1705;
    case InvalidReasonPhrase = 1706;
    case InvalidUploadedFiles = 1707;
    case InvalidParsedBody = 1708;
    case InvalidTargetPath = 1709;
    case NotAStreamResource = 1710;
    case NotAServerRequest = 1711;
    case InvalidStreamMode = 1712;
    case UnparsableUri = 1720;
    case InvalidScheme = 1721;
    case InvalidHost = 1722;
    case InvalidPort = 1723;
    case StreamDetached = 1730;
    case StreamNotSeekable = 1731;
    case StreamNotReadable = 1732;
    case StreamNotWritable = 1733;
    case StreamOperationFailed = 1734;
    case StreamOpenFailed = 1735;
    case UploadError = 1740;
    case UploadAlreadyMoved = 1741;
    case UploadMoveFailed = 1742;
    case HeadersAlreadySent = 1750;
    case CorsWildcardWithCredentials = 1760;
    case InvalidCorsOrigin = 1761;
    case NegativeMaxAge = 1762;
    case InvalidTrustedProxy = 1763;
    case InvalidBufferSize = 1764;
    case InvalidVerifyDepth = 1765;
    case UnresolvableMiddleware = 1766;

    #[\Override]
    public static function prefix(): string
    {
        return 'HTTP';
    }

    #[\Override]
    public static function block(): CodeBlock
    {
        return new CodeBlock(1700, 1799);
    }
}
