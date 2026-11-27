<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Security\PeerVerifyMode;
use PHPUnit\Framework\TestCase;

final class PeerVerifyModeTest extends TestCase
{
    public function testNoneDoesNotVerifyPeer(): void
    {
        self::assertFalse(PeerVerifyMode::None->verifyPeer());
        self::assertFalse(PeerVerifyMode::None->requireClientCert());
    }

    public function testOptionalVerifiesPeerButDoesNotRequire(): void
    {
        self::assertTrue(PeerVerifyMode::Optional->verifyPeer());
        self::assertFalse(PeerVerifyMode::Optional->requireClientCert());
    }

    public function testRequireVerifiesAndRequires(): void
    {
        self::assertTrue(PeerVerifyMode::Require->verifyPeer());
        self::assertTrue(PeerVerifyMode::Require->requireClientCert());
    }

    public function testAllCasesExist(): void
    {
        $cases = PeerVerifyMode::cases();

        self::assertCount(3, $cases);
        self::assertContains(PeerVerifyMode::None, $cases);
        self::assertContains(PeerVerifyMode::Optional, $cases);
        self::assertContains(PeerVerifyMode::Require, $cases);
    }
}
