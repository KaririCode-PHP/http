<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Security;

use KaririCode\Http\Security\DistinguishedName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DistinguishedName::class)]
final class DistinguishedNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function rfc4514(): iterable
    {
        yield 'plain' => ['CN=api,O=Acme,C=BR', [['CN', 'api'], ['O', 'Acme'], ['C', 'BR']]];
        yield 'escaped comma' => ['CN=api\, v2,O=Acme', [['CN', 'api, v2'], ['O', 'Acme']]];
        yield 'hex escape' => ['CN=api\2C v2', [['CN', 'api, v2']]];
        yield 'lower-case hex escape' => ['CN=caf\c3\a9', [['CN', 'café']]];
        yield 'escaped backslash' => ['CN=a\\\\b', [['CN', 'a\b']]];
        yield 'escaped separator cannot start an attribute' => ['O=a\,CN=admin', [['O', 'a,CN=admin']]];
        yield 'multi-valued RDN' => ['CN=a+UID=b,O=x', [['CN', 'a'], ['UID', 'b'], ['O', 'x']]];
        yield 'semicolon separator' => ['CN=a;O=b', [['CN', 'a'], ['O', 'b']]];
        yield 'quoted value' => ['CN="a, b+c",O=x', [['CN', 'a, b+c'], ['O', 'x']]];
        yield 'quoted value keeps its spaces' => ['CN=" padded ",O=x', [['CN', ' padded '], ['O', 'x']]];
        yield 'escaped quote inside quotes' => ['CN="say \"hi\"",O=x', [['CN', 'say "hi"'], ['O', 'x']]];
        yield 'text after a closing quote is skipped' => ['CN="a"junk,O=x', [['CN', 'a'], ['O', 'x']]];
        yield 'spaces around types and values' => [' CN = api , O = Acme ', [['CN', 'api'], ['O', 'Acme']]];
        yield 'empty value' => ['CN=,O=x', [['CN', ''], ['O', 'x']]];
        yield 'trailing backslash' => ['CN=a\\', [['CN', 'a']]];
        yield 'single hex digit is a plain escape' => ['CN=a\4', [['CN', 'a4']]];
        yield 'equals sign in a value' => ['CN=a=b', [['CN', 'a=b']]];
        yield 'type without value' => ['CN', [['CN', '']]];
        yield 'empty' => ['', []];
    }

    /**
     * @param list<array{string, string}> $pairs
     */
    #[Test]
    #[DataProvider('rfc4514')]
    public function itReadsRfc4514Strings(string $dn, array $pairs): void
    {
        self::assertSame($pairs, DistinguishedName::parse($dn));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function oneLine(): iterable
    {
        yield 'apache default' => ['/C=BR/O=Acme Corp/CN=client-7', [['C', 'BR'], ['O', 'Acme Corp'], ['CN', 'client-7']]];
        yield 'equals in a value' => ['/CN=a=b', [['CN', 'a=b']]];
        yield 'commas are plain text here' => ['/O=a,CN=admin', [['O', 'a,CN=admin']]];
        yield 'components without "=" are skipped' => ['/C=BR//junk/CN=x', [['C', 'BR'], ['CN', 'x']]];
        yield 'type is trimmed, value is kept' => ['/ CN = x ', [['CN', ' x ']]];
        yield 'only the slash' => ['/', []];
    }

    /**
     * @param list<array{string, string}> $pairs
     */
    #[Test]
    #[DataProvider('oneLine')]
    public function itReadsTheOpenSslOneLineForm(string $dn, array $pairs): void
    {
        self::assertSame($pairs, DistinguishedName::parse($dn));
    }
}
