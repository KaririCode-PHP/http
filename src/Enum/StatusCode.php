<?php

declare(strict_types=1);

namespace KaririCode\Http\Enum;

/**
 * The HTTP status codes of RFC 9110 §15 and the IANA registry, with their
 * reason phrases and classes (informational, success, redirection, client
 * and server error). Phrases follow RFC 9110 where it renamed a status
 * (413 Content Too Large, 422 Unprocessable Content); 418 keeps the phrase
 * of RFC 2324, since RFC 9110 only reserves the code. The enum is backed by the code because the number is
 * an external contract the registry fixes (errata E-003): tryFrom() and
 * tryFromCode() find a status without a lookup table kept in static state.
 *
 * ARFA 1.3: P1 (immutable cases). Spec V4.3.3: backed enum with a
 * documented external contract (E-003).
 *
 * Preconditions: none.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     StatusCode::NotFound->code();          // 404
 *     StatusCode::tryFromCode(418)?->reasonPhrase(); // "I'm a teapot"
 *
 * References: RFC 9110 §15; RFC 2324 §2.3.2 (418); IANA HTTP Status Code
 * Registry.
 *
 * @see https://www.iana.org/assignments/http-status-codes/
 */
enum StatusCode: int
{
    // ── 1xx Informational ────────────────────────────────────────────────
    case Continue = 100;
    case SwitchingProtocols = 101;
    case Processing = 102;
    case EarlyHints = 103;

    // ── 2xx Success ──────────────────────────────────────────────────────
    case Ok = 200;
    case Created = 201;
    case Accepted = 202;
    case NonAuthoritativeInformation = 203;
    case NoContent = 204;
    case ResetContent = 205;
    case PartialContent = 206;
    case MultiStatus = 207;
    case AlreadyReported = 208;
    case ImUsed = 226;

    // ── 3xx Redirection ──────────────────────────────────────────────────
    case MultipleChoices = 300;
    case MovedPermanently = 301;
    case Found = 302;
    case SeeOther = 303;
    case NotModified = 304;
    case TemporaryRedirect = 307;
    case PermanentRedirect = 308;

    // ── 4xx Client Error ─────────────────────────────────────────────────
    case BadRequest = 400;
    case Unauthorized = 401;
    case PaymentRequired = 402;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case NotAcceptable = 406;
    case ProxyAuthenticationRequired = 407;
    case RequestTimeout = 408;
    case Conflict = 409;
    case Gone = 410;
    case LengthRequired = 411;
    case PreconditionFailed = 412;
    case ContentTooLarge = 413;
    case UriTooLong = 414;
    case UnsupportedMediaType = 415;
    case RangeNotSatisfiable = 416;
    case ExpectationFailed = 417;
    case ImATeapot = 418;
    case MisdirectedRequest = 421;
    case UnprocessableContent = 422;
    case Locked = 423;
    case FailedDependency = 424;
    case TooEarly = 425;
    case UpgradeRequired = 426;
    case PreconditionRequired = 428;
    case TooManyRequests = 429;
    case RequestHeaderFieldsTooLarge = 431;
    case UnavailableForLegalReasons = 451;

    // ── 5xx Server Error ─────────────────────────────────────────────────
    case InternalServerError = 500;
    case NotImplemented = 501;
    case BadGateway = 502;
    case ServiceUnavailable = 503;
    case GatewayTimeout = 504;
    case HttpVersionNotSupported = 505;
    case VariantAlsoNegotiates = 506;
    case InsufficientStorage = 507;
    case LoopDetected = 508;
    case NotExtended = 510;
    case NetworkAuthenticationRequired = 511;

    public function code(): int
    {
        return $this->value;
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
            self::AlreadyReported => 'Already Reported',
            self::ImUsed => 'IM Used',
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
            self::ContentTooLarge => 'Content Too Large',
            self::UriTooLong => 'URI Too Long',
            self::UnsupportedMediaType => 'Unsupported Media Type',
            self::RangeNotSatisfiable => 'Range Not Satisfiable',
            self::ExpectationFailed => 'Expectation Failed',
            self::ImATeapot => "I'm a teapot",
            self::MisdirectedRequest => 'Misdirected Request',
            self::UnprocessableContent => 'Unprocessable Content',
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
        return intdiv($this->value, 100) === 1;
    }

    public function isSuccess(): bool
    {
        return intdiv($this->value, 100) === 2;
    }

    public function isRedirection(): bool
    {
        return intdiv($this->value, 100) === 3;
    }

    public function isClientError(): bool
    {
        return intdiv($this->value, 100) === 4;
    }

    public function isServerError(): bool
    {
        return intdiv($this->value, 100) === 5;
    }

    /**
     * @return array<int, string> code => reason phrase, for every known status
     */
    public static function phrases(): array
    {
        $phrases = [];

        foreach (self::cases() as $case) {
            $phrases[$case->value] = $case->reasonPhrase();
        }

        return $phrases;
    }

    /** The status with this code, or null for a code the registry does not list. */
    public static function tryFromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }
}
