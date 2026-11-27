<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Conformance;

use KaririCode\Exception\ErrorCodeTable;
use KaririCode\Http\Exception\HttpCode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HttpCode against the ecosystem registry of record, CODE-BLOCKS.md of
 * kariricode/exception, read from the installed package (ADR-003).
 */
#[CoversNothing]
final class CodeBlockRegistryTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../vendor/kariricode/exception/CODE-BLOCKS.md';

    #[Test]
    public function httpCodeIsAValidCodeTable(): void
    {
        $table = new ErrorCodeTable(HttpCode::class);

        self::assertSame('HTTP', $table->prefix);
        self::assertSame([1700, 1799], [$table->block->first, $table->block->last]);
    }

    #[Test]
    public function theRegistryGivesThisPackageItsBlockAndPrefix(): void
    {
        $rows = array_values(array_filter(
            file(self::REGISTRY, FILE_IGNORE_NEW_LINES) ?: [],
            static fn(string $line): bool => str_contains($line, '| kariricode/http |'),
        ));

        self::assertCount(1, $rows, 'one block for kariricode/http');
        self::assertMatchesRegularExpression('/\A\| 1700–1799 \| HTTP \| kariricode\/http \| (released|claimed) \|/u', $rows[0]);
    }

    #[Test]
    public function codesFollowTheDocumentedLayout(): void
    {
        $ranges = [
            'message arguments' => [1700, 1719],
            'URIs' => [1720, 1729],
            'streams' => [1730, 1739],
            'uploaded files' => [1740, 1749],
            'SAPI emitter' => [1750, 1759],
            'configuration' => [1760, 1779],
        ];

        foreach (HttpCode::cases() as $case) {
            $inRange = array_filter($ranges, static fn(array $range): bool => $case->value >= $range[0] && $case->value <= $range[1]);
            self::assertCount(1, $inRange, $case->name . ' sits in a documented range');
        }
    }
}
