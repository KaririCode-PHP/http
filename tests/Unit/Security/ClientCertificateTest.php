<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Security\ClientCertificate;
use PHPUnit\Framework\TestCase;

final class ClientCertificateTest extends TestCase
{
    // ── Constructor ──────────────────────────────────────────────────────

    public function testConstructorWithAllFields(): void
    {
        $validFrom = new \DateTimeImmutable('2024-01-01');
        $validTo = new \DateTimeImmutable('2025-12-31');

        $cert = new ClientCertificate(
            subjectDn: 'CN=service-a,O=Acme,C=BR',
            issuerDn: 'CN=Internal CA,O=Acme',
            serialNumber: 'DEADBEEF',
            fingerprint: 'aabb',
            validFrom: $validFrom,
            validTo: $validTo,
            pem: '-----BEGIN CERTIFICATE-----',
        );

        self::assertSame('CN=service-a,O=Acme,C=BR', $cert->subjectDn);
        self::assertSame('CN=Internal CA,O=Acme', $cert->issuerDn);
        self::assertSame('DEADBEEF', $cert->serialNumber);
        self::assertSame('aabb', $cert->fingerprint);
        self::assertSame($validFrom, $cert->validFrom);
        self::assertSame($validTo, $cert->validTo);
    }

    public function testDefaultsAreEmpty(): void
    {
        $cert = new ClientCertificate(subjectDn: 'CN=test');

        self::assertSame('', $cert->issuerDn);
        self::assertSame('', $cert->serialNumber);
        self::assertSame('', $cert->fingerprint);
        self::assertSame('', $cert->pem);
    }

    // ── fromProxyHeaders ─────────────────────────────────────────────────

    public function testFromProxyHeadersStandardNginx(): void
    {
        $cert = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => 'CN=api-gateway,O=Corp',
            'x-ssl-client-issuer' => 'CN=Root CA,O=Corp',
            'x-ssl-client-serial' => 'FF01',
            'x-ssl-client-sha256' => 'aabbccdd',
            'x-ssl-client-notbefore' => '2024-01-01T00:00:00Z',
            'x-ssl-client-notafter' => '2025-12-31T23:59:59Z',
        ]);

        self::assertSame('CN=api-gateway,O=Corp', $cert->subjectDn);
        self::assertSame('CN=Root CA,O=Corp', $cert->issuerDn);
        self::assertSame('FF01', $cert->serialNumber);
        self::assertSame('aabbccdd', $cert->fingerprint);
        self::assertSame('2024-01-01', $cert->validFrom->format('Y-m-d'));
        self::assertSame('2025-12-31', $cert->validTo->format('Y-m-d'));
    }

    public function testFromProxyHeadersAlternateNames(): void
    {
        $cert = ClientCertificate::fromProxyHeaders([
            'x-client-dn' => 'CN=service-b',
            'x-client-issuer-dn' => 'CN=CA',
            'x-ssl-client-fingerprint' => '1122',
        ]);

        self::assertSame('CN=service-b', $cert->subjectDn);
        self::assertSame('CN=CA', $cert->issuerDn);
        self::assertSame('1122', $cert->fingerprint);
    }

    public function testFromProxyHeadersUrlDecodedPem(): void
    {
        $encoded = urlencode("-----BEGIN CERTIFICATE-----\nMIIB...");
        $cert = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => 'CN=test',
            'x-ssl-client-cert' => $encoded,
        ]);

        self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $cert->pem);
    }

    public function testFromProxyHeadersEmptyReturnsEmptyCert(): void
    {
        $cert = ClientCertificate::fromProxyHeaders([]);

        self::assertTrue($cert->isEmpty());
        self::assertSame('', $cert->subjectDn);
    }

    public function testFromProxyHeadersInvalidDateFallsBack(): void
    {
        $cert = ClientCertificate::fromProxyHeaders([
            'x-ssl-client-dn' => 'CN=test',
            'x-ssl-client-notbefore' => 'not-a-date',
            'x-ssl-client-notafter' => 'also-not-a-date',
        ]);

        self::assertSame('1970-01-01', $cert->validFrom->format('Y-m-d'));
        self::assertSame('1970-01-01', $cert->validTo->format('Y-m-d'));
    }

    // ── Query Methods ────────────────────────────────────────────────────

    public function testCommonName(): void
    {
        $cert = new ClientCertificate(subjectDn: 'CN=my-service,O=Acme,C=BR');

        self::assertSame('my-service', $cert->commonName());
    }

    public function testCommonNameEmpty(): void
    {
        $cert = new ClientCertificate(subjectDn: 'O=Acme');

        self::assertSame('', $cert->commonName());
    }

    public function testOrganization(): void
    {
        $cert = new ClientCertificate(subjectDn: 'CN=svc,O=Acme Corp,C=BR');

        self::assertSame('Acme Corp', $cert->organization());
    }

    public function testOrganizationEmpty(): void
    {
        $cert = new ClientCertificate(subjectDn: 'CN=svc');

        self::assertSame('', $cert->organization());
    }

    public function testIsEmpty(): void
    {
        self::assertTrue((new ClientCertificate(subjectDn: ''))->isEmpty());
        self::assertFalse((new ClientCertificate(subjectDn: 'CN=x'))->isEmpty());
    }

    // ── Validity Period ──────────────────────────────────────────────────

    public function testIsExpired(): void
    {
        $cert = new ClientCertificate(
            subjectDn: 'CN=test',
            validFrom: new \DateTimeImmutable('2020-01-01'),
            validTo: new \DateTimeImmutable('2023-12-31'),
        );

        $now = new \DateTimeImmutable('2025-06-15');

        self::assertTrue($cert->isExpired($now));
        self::assertFalse($cert->isNotYetValid($now));
        self::assertFalse($cert->isWithinValidityPeriod($now));
    }

    public function testIsNotYetValid(): void
    {
        $cert = new ClientCertificate(
            subjectDn: 'CN=test',
            validFrom: new \DateTimeImmutable('2030-01-01'),
            validTo: new \DateTimeImmutable('2031-12-31'),
        );

        $now = new \DateTimeImmutable('2025-06-15');

        self::assertFalse($cert->isExpired($now));
        self::assertTrue($cert->isNotYetValid($now));
        self::assertFalse($cert->isWithinValidityPeriod($now));
    }

    public function testIsWithinValidityPeriod(): void
    {
        $cert = new ClientCertificate(
            subjectDn: 'CN=test',
            validFrom: new \DateTimeImmutable('2024-01-01'),
            validTo: new \DateTimeImmutable('2026-12-31'),
        );

        $now = new \DateTimeImmutable('2025-06-15');

        self::assertFalse($cert->isExpired($now));
        self::assertFalse($cert->isNotYetValid($now));
        self::assertTrue($cert->isWithinValidityPeriod($now));
    }
}
