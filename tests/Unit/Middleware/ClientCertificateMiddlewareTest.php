<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Middleware;

use KaririCode\Http\Message\Response;
use KaririCode\Http\Message\ServerRequest;
use KaririCode\Http\Middleware\ClientCertificateMiddleware;
use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\SystemClock;
use KaririCode\Http\Security\TlsConfiguration;
use KaririCode\Http\Tests\Support\FixedClock;
use KaririCode\Http\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ClientCertificateMiddleware::class)]
#[CoversClass(SystemClock::class)]
final class ClientCertificateMiddlewareTest extends TestCase
{
    private const string PROXY = '10.0.0.5';

    private const string CLIENT = '203.0.113.9';

    private const array VALID_PERIOD = [
        'SSL_CLIENT_V_START' => 'Jan  1 00:00:00 2026 GMT',
        'SSL_CLIENT_V_END' => 'Dec 31 23:59:59 2027 GMT',
    ];

    // ── Without a certificate ────────────────────────────────────────────

    #[Test]
    public function requireModeRefusesARequestWithoutCertificate(): void
    {
        $response = $this->process(self::mutual(), $this->request());

        self::assertForbidden('Client certificate required', $response);
    }

    #[Test]
    public function optionalAndNoneModesLetARequestWithoutCertificateThrough(): void
    {
        foreach ([TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem'), new TlsConfiguration()] as $config) {
            $handler = new RecordingHandler();
            $response = $this->middleware($config)->process($this->request(), $handler);

            self::assertSame(200, $response->getStatusCode());
            self::assertNull($handler->last()->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY));
        }
    }

    #[Test]
    public function serverVerifyNoneMeansNoCertificate(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'NONE']);

        self::assertForbidden('Client certificate required', $this->process(self::mutual(), $request));
        self::assertSame(200, $this->process(TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem'), $request)->getStatusCode());
    }

    // ── Certificates the web server verified ─────────────────────────────

    #[Test]
    public function aCertificateTheServerVerifiedReachesTheHandler(): void
    {
        $handler = new RecordingHandler();
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=service-a,O=Corp', 'SSL_CLIENT_I_DN' => 'CN=Root CA'] + self::VALID_PERIOD);

        $response = $this->middleware(self::mutual())->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        $certificate = $handler->last()->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY);
        self::assertInstanceOf(ClientCertificate::class, $certificate);
        self::assertSame('CN=service-a,O=Corp', $certificate->subjectDn);
        self::assertSame('CN=Root CA', $certificate->issuerDn);
    }

    #[Test]
    public function theVerifyResultIsReadWithoutRegardToCase(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'success', 'SSL_CLIENT_S_DN' => 'CN=service-a'] + self::VALID_PERIOD);

        self::assertSame(200, $this->process(self::mutual(), $request)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedVerifications(): iterable
    {
        yield 'failed with reason' => ['FAILED:certificate has expired'];
        yield 'generous' => ['GENEROUS'];
        yield 'plain failed' => ['FAILED'];
    }

    #[Test]
    #[DataProvider('failedVerifications')]
    public function anyResultOtherThanSuccessIsAFailedVerification(string $verify): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => $verify, 'SSL_CLIENT_S_DN' => 'CN=service-a'] + self::VALID_PERIOD);

        self::assertForbidden('Client certificate verification failed', $this->process(self::mutual(), $request));
        self::assertForbidden('Client certificate verification failed', $this->process(TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem'), $request));
    }

    #[Test]
    public function aVerifiedCertificateWithoutSubjectIsRefusedWhenRequiredAndIgnoredWhenOptional(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS']);
        $handler = new RecordingHandler();

        self::assertForbidden('Client certificate could not be parsed', $this->process(self::mutual(), $request));
        self::assertSame(200, $this->middleware(TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem'))->process($request, $handler)->getStatusCode());
        self::assertNull($handler->last()->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY));
    }

    // ── Headers from proxies ─────────────────────────────────────────────

    #[Test]
    public function proxyHeadersFromAnUntrustedPeerAreIgnoredAndRemoved(): void
    {
        $config = TlsConfiguration::optionalClient('/c.pem', '/k.pem', '/ca.pem')->withTrustedProxies([self::PROXY]);
        $handler = new RecordingHandler();
        $forged = $this->forgedRequest(self::CLIENT);

        $response = $this->middleware($config)->process($forged, $handler);

        self::assertSame(200, $response->getStatusCode());
        $seen = $handler->last();
        self::assertNull($seen->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY));
        self::assertFalse($seen->hasHeader('X-SSL-Client-Verify'));
        self::assertFalse($seen->hasHeader('X-SSL-Client-DN'));
        self::assertFalse($seen->hasHeader('X-SSL-Client-SHA256'));
        self::assertSame('kept', $seen->getHeaderLine('X-Other'));
    }

    #[Test]
    public function forgedProxyHeadersCannotSatisfyRequireMode(): void
    {
        $withoutProxies = self::mutual();
        $withOtherProxy = self::mutual()->withTrustedProxies([self::PROXY]);

        self::assertForbidden('Client certificate required', $this->process($withoutProxies, $this->forgedRequest(self::PROXY)));
        self::assertForbidden('Client certificate required', $this->process($withOtherProxy, $this->forgedRequest(self::CLIENT)));
        self::assertForbidden('Client certificate required', $this->process($withOtherProxy, $this->forgedRequest(null)));
    }

    #[Test]
    public function headersFromATrustedProxyCarryTheCertificate(): void
    {
        $config = self::mutual()->withTrustedProxies(['10.0.0.0/8']);
        $handler = new RecordingHandler();

        $response = $this->middleware($config)->process($this->forgedRequest(self::PROXY), $handler);

        self::assertSame(200, $response->getStatusCode());
        $certificate = $handler->last()->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY);
        self::assertInstanceOf(ClientCertificate::class, $certificate);
        self::assertSame('CN=admin,O=Corp', $certificate->subjectDn);
        self::assertSame('aabbccdd', $certificate->fingerprint);
        self::assertTrue($handler->last()->hasHeader('X-SSL-Client-DN'));
    }

    #[Test]
    public function aTrustedProxyThatReportsAFailureIsRefused(): void
    {
        $config = self::mutual()->withTrustedProxies([self::PROXY]);
        $request = $this->forgedRequest(self::PROXY)->withHeader('X-SSL-Client-Verify', 'FAILED');

        self::assertForbidden('Client certificate verification failed', $this->process($config, $request));
    }

    #[Test]
    public function aTrustedProxyThatReportsNothingMeansNoCertificate(): void
    {
        $config = self::mutual()->withTrustedProxies([self::PROXY]);
        $request = $this->forgedRequest(self::PROXY)->withoutHeader('X-SSL-Client-Verify');

        self::assertForbidden('Client certificate required', $this->process($config, $request));
    }

    #[Test]
    public function theServerVariablesWinOverHeadersEvenFromATrustedProxy(): void
    {
        $config = self::mutual()->withTrustedProxies([self::PROXY]);
        $handler = new RecordingHandler();
        $request = $this->request(['REMOTE_ADDR' => self::PROXY, 'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=from-server'] + self::VALID_PERIOD)
            ->withHeader('X-SSL-Client-Verify', 'SUCCESS')
            ->withHeader('X-SSL-Client-DN', 'CN=from-header');

        $this->middleware($config)->process($request, $handler);

        $certificate = $handler->last()->getAttribute(ClientCertificateMiddleware::ATTRIBUTE_KEY);
        self::assertInstanceOf(ClientCertificate::class, $certificate);
        self::assertSame('CN=from-server', $certificate->subjectDn);
        self::assertFalse($handler->last()->hasHeader('X-SSL-Client-DN'));
    }

    // ── Policy checks ────────────────────────────────────────────────────

    #[Test]
    public function theValidityPeriodIsCheckedAgainstTheClock(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=service-a'] + self::VALID_PERIOD);

        self::assertForbidden('Client certificate is expired or not yet valid', $this->process(self::mutual(), $request, '2028-01-01T00:00:00Z'));
        self::assertForbidden('Client certificate is expired or not yet valid', $this->process(self::mutual(), $request, '2025-12-31T23:59:59Z'));
        self::assertSame(200, $this->process(self::mutual(), $request, '2027-12-31T23:59:59Z')->getStatusCode());
    }

    #[Test]
    public function unknownDatesDoNotRefuseACertificate(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=service-a', 'SSL_CLIENT_V_END' => 'tomorrow']);

        self::assertSame(200, $this->process(self::mutual(), $request, '2099-01-01T00:00:00Z')->getStatusCode());
    }

    #[Test]
    public function theDnAllowListIsEnforced(): void
    {
        $config = self::mutual()->withAllowedDns(['CN=api-gateway,O=Corp']);
        $allowed = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=api-gateway,O=Corp'] + self::VALID_PERIOD);
        $other = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=evil,O=Corp'] + self::VALID_PERIOD);

        self::assertSame(200, $this->process($config, $allowed)->getStatusCode());
        self::assertForbidden('Client certificate DN not authorized', $this->process($config, $other));
    }

    #[Test]
    public function pinnedFingerprintsAreEnforced(): void
    {
        $config = self::mutual()->withTrustedProxies([self::PROXY])->withTrustedFingerprints(['AA:BB:CC:DD']);
        $pinned = $this->forgedRequest(self::PROXY);
        $other = $pinned->withHeader('X-SSL-Client-SHA256', '11223344');
        $missing = $pinned->withoutHeader('X-SSL-Client-SHA256');

        self::assertSame(200, $this->process($config, $pinned)->getStatusCode());
        self::assertForbidden('Client certificate fingerprint not trusted', $this->process($config, $other));
        self::assertForbidden('Client certificate fingerprint not trusted', $this->process($config, $missing));
    }

    #[Test]
    public function theSystemClockIsTheDefault(): void
    {
        $request = $this->request(['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_S_DN' => 'CN=service-a', 'SSL_CLIENT_V_START' => 'Jan  1 00:00:00 2000 GMT', 'SSL_CLIENT_V_END' => 'Jan  1 00:00:00 2001 GMT']);

        $response = (new ClientCertificateMiddleware(self::mutual()))->process($request, new RecordingHandler());

        self::assertForbidden('Client certificate is expired or not yet valid', $response);
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    #[Test]
    public function refusalsAreJson(): void
    {
        $response = $this->process(self::mutual(), $this->request());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'certificate_required', 'message' => 'Client certificate required'], json_decode((string) $response->getBody(), true));
        self::assertSame('client_certificate', ClientCertificateMiddleware::ATTRIBUTE_KEY);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function mutual(): TlsConfiguration
    {
        return TlsConfiguration::mutual('/c.pem', '/k.pem', '/ca.pem');
    }

    private static function assertForbidden(string $message, ResponseInterface $response): void
    {
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame($message, $body['message'] ?? null);
    }

    private function middleware(TlsConfiguration $config, string $now = '2026-11-27T12:00:00Z'): ClientCertificateMiddleware
    {
        return new ClientCertificateMiddleware($config, new FixedClock($now));
    }

    private function process(TlsConfiguration $config, ServerRequestInterface $request, string $now = '2026-11-27T12:00:00Z'): ResponseInterface
    {
        return $this->middleware($config, $now)->process($request, new RecordingHandler(new Response(200)));
    }

    /**
     * @param array<string, string> $server
     */
    private function request(array $server = []): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://api.example.com/data', serverParams: $server + ['REMOTE_ADDR' => self::CLIENT]);
    }

    /** X-SSL-Client-* headers as a proxy writes them, sent from $peer (null: no REMOTE_ADDR at all). */
    private function forgedRequest(?string $peer): ServerRequestInterface
    {
        $server = $peer === null ? [] : ['REMOTE_ADDR' => $peer];

        return (new ServerRequest('GET', 'https://api.example.com/data', serverParams: $server))
            ->withHeader('X-SSL-Client-Verify', 'SUCCESS')
            ->withHeader('X-SSL-Client-DN', 'CN=admin,O=Corp')
            ->withHeader('X-SSL-Client-SHA256', 'aabbccdd')
            ->withHeader('X-SSL-Client-NotBefore', '2026-01-01T00:00:00Z')
            ->withHeader('X-SSL-Client-NotAfter', '2027-12-31T23:59:59Z')
            ->withHeader('X-Other', 'kept');
    }
}
