<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Message\Silently;
use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\DistinguishedName;
use KaririCode\Http\Tests\Support\TestCertificate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientCertificate::class)]
#[CoversClass(DistinguishedName::class)]
#[CoversClass(Silently::class)]
final class ClientCertificateTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────

    #[Test]
    public function itKeepsWhatItIsGiven(): void
    {
        $from = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new \DateTimeImmutable('2027-12-31T23:59:59Z');

        $certificate = new ClientCertificate('CN=service-a,O=Acme,C=BR', 'CN=Internal CA', 'DEADBEEF', 'aabb', $from, $to, '-----BEGIN CERTIFICATE-----');

        self::assertSame('CN=service-a,O=Acme,C=BR', $certificate->subjectDn);
        self::assertSame('CN=Internal CA', $certificate->issuerDn);
        self::assertSame('DEADBEEF', $certificate->serialNumber);
        self::assertSame('aabb', $certificate->fingerprint);
        self::assertSame($from, $certificate->validFrom);
        self::assertSame($to, $certificate->validTo);
        self::assertSame('-----BEGIN CERTIFICATE-----', $certificate->pem);
    }

    #[Test]
    public function optionalFieldsDefaultToUnknown(): void
    {
        $certificate = new ClientCertificate('CN=test');

        self::assertSame('', $certificate->issuerDn);
        self::assertSame('', $certificate->serialNumber);
        self::assertSame('', $certificate->fingerprint);
        self::assertNull($certificate->validFrom);
        self::assertNull($certificate->validTo);
        self::assertSame('', $certificate->pem);
    }

    // ── fromServerParams ─────────────────────────────────────────────────

    #[Test]
    public function serverVariablesFillEveryField(): void
    {
        $certificate = ClientCertificate::fromServerParams([
            'SSL_CLIENT_S_DN' => 'CN=api-client-7,O=Acme',
            'SSL_CLIENT_I_DN' => 'CN=Clients CA',
            'SSL_CLIENT_M_SERIAL' => '1A2B',
            'SSL_CLIENT_V_START' => 'Nov  7 12:00:00 2026 GMT',
            'SSL_CLIENT_V_END' => 'Nov 27 12:00:00 2027 GMT',
        ]);

        self::assertSame('CN=api-client-7,O=Acme', $certificate->subjectDn);
        self::assertSame('CN=Clients CA', $certificate->issuerDn);
        self::assertSame('1A2B', $certificate->serialNumber);
        self::assertSame('2026-11-07T12:00:00+00:00', $certificate->validFrom?->format(\DATE_ATOM));
        self::assertSame('2027-11-27T12:00:00+00:00', $certificate->validTo?->format(\DATE_ATOM));
        self::assertSame('', $certificate->fingerprint, 'no PEM, no fingerprint');
    }

    #[Test]
    public function serverVariablesOfTheWrongTypeCountAsMissing(): void
    {
        $certificate = ClientCertificate::fromServerParams(['SSL_CLIENT_S_DN' => ['CN=x'], 'SSL_CLIENT_M_SERIAL' => 42]);

        self::assertTrue($certificate->isEmpty());
        self::assertSame('', $certificate->serialNumber);
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function theFingerprintIsComputedFromTheServerPem(): void
    {
        $test = TestCertificate::create();

        $certificate = ClientCertificate::fromServerParams(['SSL_CLIENT_S_DN' => 'CN=client-7', 'SSL_CLIENT_CERT' => $test->pem]);

        self::assertSame($test->fingerprint, $certificate->fingerprint);
        self::assertSame($test->pem, $certificate->pem);
    }

    #[Test]
    public function aPemThatIsNotACertificateHasNoFingerprint(): void
    {
        $certificate = ClientCertificate::fromServerParams(['SSL_CLIENT_S_DN' => 'CN=x', 'SSL_CLIENT_CERT' => "-----BEGIN CERTIFICATE-----\nnot base64\n-----END CERTIFICATE-----"]);

        self::assertSame('', $certificate->fingerprint, 'and no PHP warning escapes (failOnWarning)');
    }

    // ── fromProxyHeaders ─────────────────────────────────────────────────

    #[Test]
    public function proxyHeadersFillEveryField(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => 'CN=api-gateway,O=Corp',
            'x-ssl-client-issuer' => 'CN=Root CA,O=Corp',
            'x-ssl-client-serial' => 'FF01',
            'x-ssl-client-sha256' => 'aabbccdd',
            'x-ssl-client-notbefore' => '2026-01-01T00:00:00Z',
            'x-ssl-client-notafter' => '2027-12-31T23:59:59+00:00',
        ]);

        self::assertSame('CN=api-gateway,O=Corp', $certificate->subjectDn);
        self::assertSame('CN=Root CA,O=Corp', $certificate->issuerDn);
        self::assertSame('FF01', $certificate->serialNumber);
        self::assertSame('aabbccdd', $certificate->fingerprint);
        self::assertSame('2026-01-01T00:00:00+00:00', $certificate->validFrom?->format(\DATE_ATOM));
        self::assertSame('2027-12-31T23:59:59+00:00', $certificate->validTo?->format(\DATE_ATOM));
    }

    #[Test]
    public function alternateHeaderNamesAreReadWhenThePrimaryOnesAreAbsentOrEmpty(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => '',
            'x-client-dn' => 'CN=service-b',
            'x-client-issuer-dn' => 'CN=CA',
            'x-ssl-client-fingerprint' => '1122',
        ]);

        self::assertSame('CN=service-b', $certificate->subjectDn);
        self::assertSame('CN=CA', $certificate->issuerDn);
        self::assertSame('1122', $certificate->fingerprint);
    }

    #[Test]
    public function thePrimaryHeaderWinsOverTheAlternate(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders(['x-ssl-client-dn' => 'CN=primary', 'x-client-dn' => 'CN=alternate']);

        self::assertSame('CN=primary', $certificate->subjectDn);
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function anEscapedPemIsDecodedAndItsFingerprintBeatsTheHeader(): void
    {
        $test = TestCertificate::create();

        $certificate = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => 'CN=client-7',
            'x-ssl-client-cert' => rawurlencode($test->pem),
            'x-ssl-client-sha256' => 'forged',
        ]);

        self::assertSame($test->pem, $certificate->pem);
        self::assertSame($test->fingerprint, $certificate->fingerprint);
    }

    #[Test]
    public function aPlusSignInThePemStaysAPlusSign(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders(['x-ssl-client-dn' => 'CN=x', 'x-ssl-client-cert' => 'MIIB+abc%2Bdef%0A']);

        self::assertSame("MIIB+abc+def\n", $certificate->pem, 'percent-decoding, not form decoding');
    }

    #[Test]
    public function noHeadersMeanAnEmptyCertificate(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders([]);

        self::assertTrue($certificate->isEmpty());
        self::assertSame('', $certificate->fingerprint);
        self::assertNull($certificate->validFrom);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableDates(): iterable
    {
        yield 'garbage' => ['not-a-date'];
        yield 'relative now' => ['now'];
        yield 'relative offset' => ['+10 years'];
        yield 'no time zone' => ['Nov 27 12:00:00 2027'];
        yield 'impossible day' => ['2027-02-30T00:00:00Z'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('unreadableDates')]
    public function aDateInAnotherFormatStaysUnknown(string $date): void
    {
        $certificate = ClientCertificate::fromProxyHeaders(['x-ssl-client-dn' => 'CN=test', 'x-ssl-client-notbefore' => $date, 'x-ssl-client-notafter' => $date]);

        self::assertNull($certificate->validFrom);
        self::assertNull($certificate->validTo);
    }

    #[Test]
    public function surroundingWhitespaceAroundADateIsIgnored(): void
    {
        $certificate = ClientCertificate::fromProxyHeaders(['x-ssl-client-notafter' => ' Nov 27 12:00:00 2027 GMT ']);

        self::assertSame('2027-11-27T12:00:00+00:00', $certificate->validTo?->format(\DATE_ATOM));
    }

    // ── fromOpenSslCertificate ───────────────────────────────────────────

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function anOpenSslCertificateFillsEveryField(): void
    {
        $test = TestCertificate::create(['commonName' => 'client-7', 'organizationName' => 'Acme, Inc.'], days: 30);

        $certificate = ClientCertificate::fromOpenSslCertificate($test->certificate);

        self::assertSame('CN=client-7,O=Acme\, Inc.', $certificate->subjectDn);
        self::assertSame($certificate->subjectDn, $certificate->issuerDn, 'self-signed');
        self::assertSame('client-7', $certificate->commonName());
        self::assertSame('Acme, Inc.', $certificate->organization());
        self::assertSame('1A2B', $certificate->serialNumber);
        self::assertSame($test->fingerprint, $certificate->fingerprint);
        self::assertSame($test->pem, $certificate->pem);
        self::assertNotNull($certificate->validFrom);
        self::assertNotNull($certificate->validTo);
        self::assertSame(30 * 86400, $certificate->validTo->getTimestamp() - $certificate->validFrom->getTimestamp());
        self::assertTrue($certificate->isWithinValidityPeriod($certificate->validFrom->modify('+1 day')));
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function subjectValuesAreEscapedSoTheDnReadsBackTheSame(): void
    {
        $test = TestCertificate::create(['commonName' => 'client-7', 'organizationalUnitName' => 'a+b=c;"d"\\e<f>']);

        $certificate = ClientCertificate::fromOpenSslCertificate($test->certificate);

        self::assertSame('CN=client-7,OU=a\\+b\\=c\\;\\"d\\"\\\\e\\<f\\>', $certificate->subjectDn);
        self::assertSame([['CN', 'client-7'], ['OU', 'a+b=c;"d"\\e<f>']], DistinguishedName::parse($certificate->subjectDn));
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function aRepeatedSubjectAttributeKeepsEveryValueInOrder(): void
    {
        $test = TestCertificate::create(['commonName' => 'client-7', 'organizationalUnitName' => ['ops', 'dev']]);

        $certificate = ClientCertificate::fromOpenSslCertificate($test->certificate);

        self::assertSame('CN=client-7,OU=ops,OU=dev', $certificate->subjectDn);
    }

    // ── Names ────────────────────────────────────────────────────────────

    #[Test]
    public function commonNameAndOrganizationReadTheSubject(): void
    {
        $certificate = new ClientCertificate('CN=my-service,O=Acme Corp,C=BR');

        self::assertSame('my-service', $certificate->commonName());
        self::assertSame('Acme Corp', $certificate->organization());
        self::assertSame('', (new ClientCertificate('O=Acme'))->commonName());
        self::assertSame('', (new ClientCertificate('CN=svc'))->organization());
    }

    #[Test]
    public function anEscapedSeparatorCannotSmuggleACommonName(): void
    {
        $certificate = new ClientCertificate('O=a\,CN=admin');

        self::assertSame('', $certificate->commonName());
        self::assertSame('a,CN=admin', $certificate->organization());
    }

    #[Test]
    public function attributeTypesMatchWithoutRegardToCaseAndTheFirstWins(): void
    {
        self::assertSame('first', (new ClientCertificate('cn=first,CN=second'))->commonName());
        self::assertSame('name', (new ClientCertificate('/C=BR/O=Org/CN=name'))->commonName());
    }

    #[Test]
    public function isEmptyMeansNoSubject(): void
    {
        self::assertTrue((new ClientCertificate(''))->isEmpty());
        self::assertFalse((new ClientCertificate('CN=x'))->isEmpty());
    }

    // ── Validity period ──────────────────────────────────────────────────

    #[Test]
    public function theValidityPeriodIsInclusiveAtBothEnds(): void
    {
        $from = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new \DateTimeImmutable('2026-12-31T23:59:59Z');
        $certificate = new ClientCertificate('CN=test', validFrom: $from, validTo: $to);

        self::assertTrue($certificate->isWithinValidityPeriod($from));
        self::assertTrue($certificate->isWithinValidityPeriod($to));
        self::assertTrue($certificate->isExpired($to->modify('+1 second')));
        self::assertFalse($certificate->isExpired($to));
        self::assertTrue($certificate->isNotYetValid($from->modify('-1 second')));
        self::assertFalse($certificate->isNotYetValid($from));
        self::assertFalse($certificate->isWithinValidityPeriod($to->modify('+1 second')));
        self::assertFalse($certificate->isWithinValidityPeriod($from->modify('-1 second')));
    }

    #[Test]
    public function unknownEndsNeverExpireOrStartLate(): void
    {
        $certificate = new ClientCertificate('CN=test');
        $now = new \DateTimeImmutable('2099-01-01T00:00:00Z');

        self::assertFalse($certificate->isExpired($now));
        self::assertFalse($certificate->isNotYetValid($now));
        self::assertTrue($certificate->isWithinValidityPeriod($now));
    }
}
