<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidConfigurationException;
use KaririCode\Http\Security\NetworkRange;
use KaririCode\Http\Security\PeerVerifyMode;
use KaririCode\Http\Security\TlsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TlsConfiguration::class)]
#[CoversClass(NetworkRange::class)]
#[CoversClass(PeerVerifyMode::class)]
final class TlsConfigurationTest extends TestCase
{
    // ── Defaults ─────────────────────────────────────────────────────────

    public function testDefaultsAreDisabled(): void
    {
        $config = new TlsConfiguration();

        self::assertNull($config->certFile);
        self::assertNull($config->keyFile);
        self::assertNull($config->caFile);
        self::assertNull($config->caPath);
        self::assertSame(PeerVerifyMode::None, $config->peerVerifyMode);
        self::assertSame(10, $config->verifyDepth);
        self::assertFalse($config->isMutualTls());
        self::assertFalse($config->hasServerCertificate());
    }

    // ── Static Factories ─────────────────────────────────────────────────

    public function testServerOnlyFactory(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');

        self::assertSame('/cert.pem', $config->certFile);
        self::assertSame('/key.pem', $config->keyFile);
        self::assertNull($config->caFile);
        self::assertSame(PeerVerifyMode::None, $config->peerVerifyMode);
        self::assertTrue($config->hasServerCertificate());
        self::assertFalse($config->isMutualTls());
    }

    public function testMutualFactory(): void
    {
        $config = TlsConfiguration::mutual('/cert.pem', '/key.pem', '/ca.pem');

        self::assertSame('/cert.pem', $config->certFile);
        self::assertSame('/key.pem', $config->keyFile);
        self::assertSame('/ca.pem', $config->caFile);
        self::assertSame(PeerVerifyMode::Require, $config->peerVerifyMode);
        self::assertTrue($config->isMutualTls());
        self::assertTrue($config->hasServerCertificate());
    }

    public function testOptionalClientFactory(): void
    {
        $config = TlsConfiguration::optionalClient('/cert.pem', '/key.pem', '/ca.pem');

        self::assertSame(PeerVerifyMode::Optional, $config->peerVerifyMode);
        self::assertTrue($config->isMutualTls());
    }

    // ── Wither Methods ───────────────────────────────────────────────────

    public function testWithPeerVerifyModeReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withPeerVerifyMode(PeerVerifyMode::Require);

        self::assertNotSame($original, $modified);
        self::assertSame(PeerVerifyMode::None, $original->peerVerifyMode);
        self::assertSame(PeerVerifyMode::Require, $modified->peerVerifyMode);
    }

    public function testWithVerifyDepthReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withVerifyDepth(5);

        self::assertSame(10, $original->verifyDepth);
        self::assertSame(5, $modified->verifyDepth);
    }

    public function testWithCipherListReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withCipherList('ECDHE-RSA-AES256-GCM-SHA384');

        self::assertNull($original->cipherList);
        self::assertSame('ECDHE-RSA-AES256-GCM-SHA384', $modified->cipherList);
    }

    public function testWithCipherSuitesReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withCipherSuites('TLS_AES_256_GCM_SHA384');

        self::assertNull($original->cipherSuites);
        self::assertSame('TLS_AES_256_GCM_SHA384', $modified->cipherSuites);
    }

    public function testWithAllowedDnsReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withAllowedDns(['CN=service-a,O=Acme']);

        self::assertSame([], $original->allowedDns);
        self::assertSame(['CN=service-a,O=Acme'], $modified->allowedDns);
    }

    public function testWithTrustedFingerprintsReturnsNewInstance(): void
    {
        $original = new TlsConfiguration();
        $modified = $original->withTrustedFingerprints(['aa:bb:cc:dd']);

        self::assertSame([], $original->trustedFingerprints);
        self::assertSame(['aa:bb:cc:dd'], $modified->trustedFingerprints);
    }

    public function testWitherChaining(): void
    {
        $config = TlsConfiguration::mutual('/cert.pem', '/key.pem', '/ca.pem')
            ->withVerifyDepth(3)
            ->withAllowedDns(['CN=api-gw'])
            ->withTrustedFingerprints(['aabbccdd'])
            ->withCipherList('HIGH');

        self::assertSame(3, $config->verifyDepth);
        self::assertSame(['CN=api-gw'], $config->allowedDns);
        self::assertSame(['aabbccdd'], $config->trustedFingerprints);
        self::assertSame('HIGH', $config->cipherList);
        self::assertTrue($config->isMutualTls());
    }

    // ── Query Methods ────────────────────────────────────────────────────

    public function testIsDnAllowedWithEmptyList(): void
    {
        $config = new TlsConfiguration();

        self::assertTrue($config->isDnAllowed('CN=anything'));
        self::assertFalse($config->hasDnRestriction());
    }

    public function testIsDnAllowedWithRestriction(): void
    {
        $config = (new TlsConfiguration())->withAllowedDns(['CN=service-a,O=Acme', 'CN=service-b,O=Acme']);

        self::assertTrue($config->hasDnRestriction());
        self::assertTrue($config->isDnAllowed('CN=service-a,O=Acme'));
        self::assertTrue($config->isDnAllowed('CN=service-b,O=Acme'));
        self::assertFalse($config->isDnAllowed('CN=evil,O=Hacker'));
    }

    public function testIsFingerprintTrustedWithEmptyList(): void
    {
        $config = new TlsConfiguration();

        self::assertTrue($config->isFingerprintTrusted('anything'));
        self::assertFalse($config->hasFingerprintPinning());
    }

    public function testIsFingerprintTrustedWithPinning(): void
    {
        $config = (new TlsConfiguration())->withTrustedFingerprints(['aa:bb:cc:dd:ee:ff']);

        self::assertTrue($config->hasFingerprintPinning());
        self::assertTrue($config->isFingerprintTrusted('aa:bb:cc:dd:ee:ff'));
        self::assertTrue($config->isFingerprintTrusted('AA:BB:CC:DD:EE:FF'));
        self::assertTrue($config->isFingerprintTrusted('aabbccddeeff'));
        self::assertFalse($config->isFingerprintTrusted('11:22:33:44:55:66'));
    }

    // ── Stream Context ───────────────────────────────────────────────────

    public function testStreamContextWithServerOnly(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');
        $options = $config->toStreamContextOptions();

        self::assertSame('/cert.pem', $options['local_cert']);
        self::assertSame('/key.pem', $options['local_pk']);
        self::assertFalse($options['verify_peer']);
        self::assertArrayNotHasKey('cafile', $options);
    }

    public function testStreamContextWithMutualTls(): void
    {
        $config = TlsConfiguration::mutual('/cert.pem', '/key.pem', '/ca.pem');
        $options = $config->toStreamContextOptions();

        self::assertTrue($options['verify_peer']);
        self::assertSame('/ca.pem', $options['cafile']);
        self::assertSame('/cert.pem', $options['local_cert']);
        self::assertSame(10, $options['verify_depth']);
        self::assertTrue($options['capture_peer_cert']);
    }

    public function testStreamContextWithCiphers(): void
    {
        $config = (new TlsConfiguration())->withCipherList('HIGH:!aNULL');
        $options = $config->toStreamContextOptions();

        self::assertSame('HIGH:!aNULL', $options['ciphers']);
    }

    public function testStreamContextWithCaPath(): void
    {
        $config = new TlsConfiguration(caPath: '/etc/ssl/certs');
        $options = $config->toStreamContextOptions();

        self::assertSame('/etc/ssl/certs', $options['capath']);
    }

    public function testStreamContextOmitsNullFields(): void
    {
        $config = new TlsConfiguration();
        $options = $config->toStreamContextOptions();

        self::assertArrayNotHasKey('local_cert', $options);
        self::assertArrayNotHasKey('local_pk', $options);
        self::assertArrayNotHasKey('cafile', $options);
        self::assertArrayNotHasKey('capath', $options);
        self::assertArrayNotHasKey('ciphers', $options);
    }

    // ── Validation ───────────────────────────────────────────────────────

    #[Test]
    public function aVerifyDepthBelowOneIsRefused(): void
    {
        self::assertSame(1, (new TlsConfiguration(verifyDepth: 1))->verifyDepth);

        foreach ([static fn(): TlsConfiguration => new TlsConfiguration(verifyDepth: 0), static fn(): TlsConfiguration => (new TlsConfiguration())->withVerifyDepth(-1)] as $build) {
            try {
                $build();
                self::fail('a depth below 1 was accepted');
            } catch (InvalidConfigurationException $e) {
                self::assertSame(HttpCode::InvalidVerifyDepth, $e->errorCode);
            }
        }
    }

    #[Test]
    public function aTrustedProxyMustBeAnAddressOrRange(): void
    {
        try {
            (new TlsConfiguration())->withTrustedProxies(['10.0.0.0/8', 'proxy.internal']);
            self::fail('a host name was accepted as a trusted proxy');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(HttpCode::InvalidTrustedProxy, $e->errorCode);
            self::assertStringContainsString('"proxy.internal"', $e->getMessage());
        }
    }

    // ── Trusted proxies ──────────────────────────────────────────────────

    #[Test]
    public function onlyListedPeersAreTrustedProxies(): void
    {
        $config = (new TlsConfiguration())->withTrustedProxies(['10.0.0.0/8', '2001:db8::1']);

        self::assertSame(['10.0.0.0/8', '2001:db8::1'], $config->trustedProxies);
        self::assertTrue($config->isTrustedProxy('10.20.30.40'));
        self::assertTrue($config->isTrustedProxy('2001:db8::1'));
        self::assertFalse($config->isTrustedProxy('11.0.0.1'));
        self::assertFalse($config->isTrustedProxy(''));
        self::assertFalse((new TlsConfiguration())->isTrustedProxy('10.0.0.1'), 'no proxies, no trust');
    }

    #[Test]
    public function everyWitherKeepsTheTrustedProxies(): void
    {
        $config = (new TlsConfiguration())->withTrustedProxies(['10.0.0.1'])
            ->withPeerVerifyMode(PeerVerifyMode::Require)
            ->withVerifyDepth(3)
            ->withCipherList('HIGH')
            ->withCipherSuites('TLS_AES_256_GCM_SHA384')
            ->withAllowedDns(['CN=a'])
            ->withTrustedFingerprints(['aa']);

        self::assertSame(['10.0.0.1'], $config->trustedProxies);
        self::assertSame(PeerVerifyMode::Require, $config->peerVerifyMode);
        self::assertSame(3, $config->verifyDepth);
        self::assertSame('HIGH', $config->cipherList);
        self::assertSame('TLS_AES_256_GCM_SHA384', $config->cipherSuites);
        self::assertSame(['CN=a'], $config->allowedDns);
        self::assertSame(['aa'], $config->trustedFingerprints);
    }

    // ── Fingerprints ─────────────────────────────────────────────────────

    #[Test]
    public function fingerprintsCompareHexDigitsOnly(): void
    {
        $config = (new TlsConfiguration())->withTrustedFingerprints(['AA:BB:CC', '11 22 33']);

        self::assertTrue($config->isFingerprintTrusted('aabbcc'));
        self::assertTrue($config->isFingerprintTrusted('11:22:33'));
        self::assertFalse($config->isFingerprintTrusted('aabbc'), 'a prefix is not a match');
        self::assertFalse($config->isFingerprintTrusted('aabbccdd'), 'a longer value is not a match');
        self::assertFalse($config->isFingerprintTrusted(''), 'a missing fingerprint never matches');
        self::assertFalse($config->isFingerprintTrusted('::'), 'separators alone are not a fingerprint');
    }

    #[Test]
    public function capturingThePeerCertificateFollowsPeerVerification(): void
    {
        self::assertTrue(TlsConfiguration::mutual('/c', '/k', '/ca')->toStreamContextOptions()['capture_peer_cert']);
        self::assertTrue(TlsConfiguration::optionalClient('/c', '/k', '/ca')->toStreamContextOptions()['capture_peer_cert']);
        self::assertFalse(TlsConfiguration::serverOnly('/c', '/k')->toStreamContextOptions()['capture_peer_cert']);
        self::assertFalse(TlsConfiguration::serverOnly('/c', '/k')->toStreamContextOptions()['allow_self_signed']);
    }

    #[Test]
    public function mutualTlsNeedsBothAVerifyModeAndAClientCa(): void
    {
        self::assertFalse((new TlsConfiguration(peerVerifyMode: PeerVerifyMode::Require))->isMutualTls(), 'no CA');
        self::assertFalse((new TlsConfiguration(caFile: '/ca.pem'))->isMutualTls(), 'no verification');
        self::assertTrue((new TlsConfiguration(caFile: '/ca.pem', peerVerifyMode: PeerVerifyMode::Optional))->isMutualTls());
    }

    #[Test]
    public function aServerCertificateNeedsBothCertificateAndKey(): void
    {
        self::assertFalse((new TlsConfiguration(certFile: '/c.pem'))->hasServerCertificate());
        self::assertFalse((new TlsConfiguration(keyFile: '/k.pem'))->hasServerCertificate());
        self::assertTrue((new TlsConfiguration(certFile: '/c.pem', keyFile: '/k.pem'))->hasServerCertificate());
    }
}
