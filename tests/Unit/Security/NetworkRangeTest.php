<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Security\NetworkRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkRange::class)]
final class NetworkRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function memberships(): iterable
    {
        yield 'single address' => ['10.0.0.1', '10.0.0.1', true];
        yield 'single address, other host' => ['10.0.0.1', '10.0.0.2', false];
        yield '/8 first' => ['10.0.0.0/8', '10.0.0.0', true];
        yield '/8 last' => ['10.0.0.0/8', '10.255.255.255', true];
        yield '/8 next' => ['10.0.0.0/8', '11.0.0.0', false];
        yield '/12 inside' => ['172.16.0.0/12', '172.31.255.255', true];
        yield '/12 outside' => ['172.16.0.0/12', '172.32.0.0', false];
        yield '/12 below' => ['172.16.0.0/12', '172.15.255.255', false];
        yield '/12 shares one bit only' => ['172.16.0.0/12', '172.48.0.1', false];
        yield '/25 last' => ['192.168.1.0/25', '192.168.1.127', true];
        yield '/25 next' => ['192.168.1.0/25', '192.168.1.128', false];
        yield '/31 pair' => ['192.168.1.4/31', '192.168.1.5', true];
        yield '/31 neighbour' => ['192.168.1.4/31', '192.168.1.6', false];
        yield '/32 exact' => ['192.168.1.4/32', '192.168.1.4', true];
        yield '/0 everything' => ['0.0.0.0/0', '203.0.113.9', true];
        yield 'host bits of the range are ignored' => ['10.1.2.3/8', '10.200.0.1', true];
        yield 'IPv6 loopback' => ['::1', '::1', true];
        yield 'IPv6 /32' => ['2001:db8::/32', '2001:db8:ffff::1', true];
        yield 'IPv6 /32 outside' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'IPv6 /127' => ['2001:db8::/127', '2001:db8::1', true];
        yield 'IPv6 spelling does not matter' => ['2001:db8::1', '2001:0db8:0000::0001', true];
        yield 'IPv4-mapped IPv6 is another family' => ['10.0.0.0/8', '::ffff:10.0.0.1', false];
        yield 'IPv4 is not in an IPv6 range' => ['::/0', '10.0.0.1', false];
        yield 'leading zeros are not an address' => ['10.0.0.1', '010.0.0.1', false];
        yield 'empty address' => ['10.0.0.0/8', '', false];
        yield 'host name' => ['10.0.0.0/8', 'proxy.internal', false];
    }

    #[Test]
    #[DataProvider('memberships')]
    public function itTellsWhetherAnAddressBelongs(string $range, string $address, bool $contains): void
    {
        $parsed = NetworkRange::parse($range);

        self::assertNotNull($parsed);
        self::assertSame($contains, $parsed->contains($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'host name' => ['proxy.internal'];
        yield 'leading zeros' => ['010.0.0.1'];
        yield 'IPv4 prefix too long' => ['10.0.0.0/33'];
        yield 'IPv6 prefix too long' => ['::/129'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'prefix not a number' => ['10.0.0.0/x'];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'two prefixes' => ['10.0.0.0/8/9'];
        yield 'bracketed IPv6' => ['[::1]'];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function anythingElseIsNotARange(string $range): void
    {
        self::assertNull(NetworkRange::parse($range));
    }
}
