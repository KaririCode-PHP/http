<?php

declare(strict_types=1);

namespace KaririCode\Http\Enum;

/**
 * HTTP status codes per RFC 9110 §15.
 *
 * Pure enum — no backed values. Numeric codes and reason phrases
 * derived via match() to keep the enum closed for modification.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): pure enum with
 * no backed values.
 *
 * Spec V4.0: pure enum, static memoization for phrases() and
 * tryFromCode() lookups.
 *
 * @see https://www.iana.org/assignments/http-status-codes/ IANA HTTP Status Code Registry
 */
enum StatusCode
{
    // ── 1xx Informational ────────────────────────────────────────────────
    case Continue;
    case SwitchingProtocols;
    case Processing;
    case EarlyHints;

    // ── 2xx Success ──────────────────────────────────────────────────────
    case Ok;
    case Created;
    case Accepted;
    case NonAuthoritativeInformation;
    case NoContent;
    case ResetContent;
    case PartialContent;
    case MultiStatus;

    // ── 3xx Redirection ──────────────────────────────────────────────────
    case MultipleChoices;
    case MovedPermanently;
    case Found;
    case SeeOther;
    case NotModified;
    case TemporaryRedirect;
    case PermanentRedirect;

    // ── 4xx Client Error ─────────────────────────────────────────────────
    case BadRequest;
    case Unauthorized;
    case PaymentRequired;
    case Forbidden;
    case NotFound;
    case MethodNotAllowed;
    case NotAcceptable;
    case ProxyAuthenticationRequired;
    case RequestTimeout;
    case Conflict;
    case Gone;
    case LengthRequired;
    case PreconditionFailed;
    case PayloadTooLarge;
    case UriTooLong;
    case UnsupportedMediaType;
    case RangeNotSatisfiable;
    case ExpectationFailed;
    case ImATeapot;
    case MisdirectedRequest;
    case UnprocessableEntity;
    case Locked;
    case FailedDependency;
    case TooEarly;
    case UpgradeRequired;
    case PreconditionRequired;
    case TooManyRequests;
    case RequestHeaderFieldsTooLarge;
    case UnavailableForLegalReasons;

    // ── 5xx Server Error ─────────────────────────────────────────────────
    case InternalServerError;
    case NotImplemented;
    case BadGateway;
    case ServiceUnavailable;
    case GatewayTimeout;
    case HttpVersionNotSupported;
    case VariantAlsoNegotiates;
    case InsufficientStorage;
    case LoopDetected;
    case NotExtended;
    case NetworkAuthenticationRequired;

    public function code(): int
    {
        return match ($this) {
            self::Continue => 100,
            self::SwitchingProtocols => 101,
            self::Processing => 102,
            self::EarlyHints => 103,
            self::Ok => 200,
            self::Created => 201,
            self::Accepted => 202,
            self::NonAuthoritativeInformation => 203,
            self::NoContent => 204,
            self::ResetContent => 205,
            self::PartialContent => 206,
            self::MultiStatus => 207,
            self::MultipleChoices => 300,
            self::MovedPermanently => 301,
            self::Found => 302,
            self::SeeOther => 303,
            self::NotModified => 304,
            self::TemporaryRedirect => 307,
            self::PermanentRedirect => 308,
            self::BadRequest => 400,
            self::Unauthorized => 401,
            self::PaymentRequired => 402,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::NotAcceptable => 406,
            self::ProxyAuthenticationRequired => 407,
            self::RequestTimeout => 408,
            self::Conflict => 409,
            self::Gone => 410,
            self::LengthRequired => 411,
            self::PreconditionFailed => 412,
            self::PayloadTooLarge => 413,
            self::UriTooLong => 414,
            self::UnsupportedMediaType => 415,
            self::RangeNotSatisfiable => 416,
            self::ExpectationFailed => 417,
            self::ImATeapot => 418,
            self::MisdirectedRequest => 421,
            self::UnprocessableEntity => 422,
            self::Locked => 423,
            self::FailedDependency => 424,
            self::TooEarly => 425,
            self::UpgradeRequired => 426,
            self::PreconditionRequired => 428,
            self::TooManyRequests => 429,
            self::RequestHeaderFieldsTooLarge => 431,
            self::UnavailableForLegalReasons => 451,
            self::InternalServerError => 500,
            self::NotImplemented => 501,
            self::BadGateway => 502,
            self::ServiceUnavailable => 503,
            self::GatewayTimeout => 504,
            self::HttpVersionNotSupported => 505,
            self::VariantAlsoNegotiates => 506,
            self::InsufficientStorage => 507,
            self::LoopDetected => 508,
            self::NotExtended => 510,
            self::NetworkAuthenticationRequired => 511,
        };
    }

    public function reasonPhrase(): string
    {
        return match ($this) {
            self::Continue => 'Continue',
            self::SwitchingProtocols => 'Switching Protocols',
            self::Processing => 'Processing',
            self::EarlyHints => 'Early Hints',
            self::Ok => 'OK',
            self::Created => 'Created',
            self::Accepted => 'Accepted',
            self::NonAuthoritativeInformation => 'Non-Authoritative Information',
            self::NoContent => 'No Content',
            self::ResetContent => 'Reset Content',
            self::PartialContent => 'Partial Content',
            self::MultiStatus => 'Multi-Status',
            self::MultipleChoices => 'Multiple Choices',
            self::MovedPermanently => 'Moved Permanently',
            self::Found => 'Found',
            self::SeeOther => 'See Other',
            self::NotModified => 'Not Modified',
            self::TemporaryRedirect => 'Temporary Redirect',
            self::PermanentRedirect => 'Permanent Redirect',
            self::BadRequest => 'Bad Request',
            self::Unauthorized => 'Unauthorized',
            self::PaymentRequired => 'Payment Required',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not Found',
            self::MethodNotAllowed => 'Method Not Allowed',
            self::NotAcceptable => 'Not Acceptable',
            self::ProxyAuthenticationRequired => 'Proxy Authentication Required',
            self::RequestTimeout => 'Request Timeout',
            self::Conflict => 'Conflict',
            self::Gone => 'Gone',
            self::LengthRequired => 'Length Required',
            self::PreconditionFailed => 'Precondition Failed',
            self::PayloadTooLarge => 'Payload Too Large',
            self::UriTooLong => 'URI Too Long',
            self::UnsupportedMediaType => 'Unsupported Media Type',
            self::RangeNotSatisfiable => 'Range Not Satisfiable',
            self::ExpectationFailed => 'Expectation Failed',
            self::ImATeapot => "I'm a teapot",
            self::MisdirectedRequest => 'Misdirected Request',
            self::UnprocessableEntity => 'Unprocessable Entity',
            self::Locked => 'Locked',
            self::FailedDependency => 'Failed Dependency',
            self::TooEarly => 'Too Early',
            self::UpgradeRequired => 'Upgrade Required',
            self::PreconditionRequired => 'Precondition Required',
            self::TooManyRequests => 'Too Many Requests',
            self::RequestHeaderFieldsTooLarge => 'Request Header Fields Too Large',
            self::UnavailableForLegalReasons => 'Unavailable For Legal Reasons',
            self::InternalServerError => 'Internal Server Error',
            self::NotImplemented => 'Not Implemented',
            self::BadGateway => 'Bad Gateway',
            self::ServiceUnavailable => 'Service Unavailable',
            self::GatewayTimeout => 'Gateway Timeout',
            self::HttpVersionNotSupported => 'HTTP Version Not Supported',
            self::VariantAlsoNegotiates => 'Variant Also Negotiates',
            self::InsufficientStorage => 'Insufficient Storage',
            self::LoopDetected => 'Loop Detected',
            self::NotExtended => 'Not Extended',
            self::NetworkAuthenticationRequired => 'Network Authentication Required',
        };
    }

    public function isInformational(): bool
    {
        $code = $this->code();
        return $code >= 100 && $code < 200;
    }

    public function isSuccess(): bool
    {
        $code = $this->code();
        return $code >= 200 && $code < 300;
    }

    public function isRedirection(): bool
    {
        $code = $this->code();
        return $code >= 300 && $code < 400;
    }

    public function isClientError(): bool
    {
        $code = $this->code();
        return $code >= 400 && $code < 500;
    }

    public function isServerError(): bool
    {
        $code = $this->code();
        return $code >= 500 && $code < 600;
    }

    /**
     * @return array<int, string> Code → reason phrase for all known statuses.
      *
      * @see https://www.iana.org/assignments/http-status-codes/ IANA HTTP Status Code Registry
     */
    public static function phrases(): array
    {
        static $map = null;

        return $map ??= array_reduce(
            self::cases(),
            static function (array $carry, self $case): array {
                $carry[$case->code()] = $case->reasonPhrase();
                return $carry;
            },
            [],
        );
    }

    public static function tryFromCode(int $code): ?self
    {
        static $codeMap = null;

        $codeMap ??= array_reduce(
            self::cases(),
            static function (array $carry, self $case): array {
                $carry[$case->code()] = $case;
                return $carry;
            },
            [],
        );

        return $codeMap[$code] ?? null;
    }
}

