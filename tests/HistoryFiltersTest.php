<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\HistoryFilters;
use PHPUnit\Framework\TestCase;

final class HistoryFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testBarRangeKeepsHistorySelectValues(): void
    {
        $this->assertSame('7', HistoryFilters::toBarRange('week'));
        $this->assertSame('7', HistoryFilters::toBarRange('7'));
        $this->assertSame('30', HistoryFilters::toBarRange('month'));
        $this->assertSame('30', HistoryFilters::toBarRange('nope'));
        $this->assertSame('all', HistoryFilters::toBarRange('all'));
    }

    public function testStatsRangeMapsHistorySelectValues(): void
    {
        $this->assertSame('week', HistoryFilters::toStatsRange('7'));
        $this->assertSame('month', HistoryFilters::toStatsRange('30'));
        $this->assertSame('all', HistoryFilters::toStatsRange('all'));
        $this->assertSame('all', HistoryFilters::toStatsRange('year'));
    }

    public function testRangeDaysCoversHistorySelectValues(): void
    {
        $this->assertSame(7, (new HistoryFilters(range: '7'))->rangeDays());
        $this->assertSame(30, (new HistoryFilters(range: '30'))->rangeDays());
        $this->assertNull((new HistoryFilters(range: 'all'))->rangeDays());
    }

    public function testNavSuffixKeepsHistoryRangeAndLibrary(): void
    {
        $_GET = [];
        $this->assertSame('', HistoryFilters::navSuffix());

        $_GET = ['range' => 'all', 'user' => 'Alice', 'library' => 'Movies'];
        $this->assertSame('?range=all&user=Alice&library=Movies', HistoryFilters::navSuffix());

        $_GET = ['range' => 'week'];
        $this->assertSame('?range=7', HistoryFilters::navSuffix());
    }

    public function testPathKeepsRangeWhenOpeningHistoryFromStatistics(): void
    {
        $href = HistoryFilters::path('/history', 'week', 'Alice', ['search' => 'Dune']);

        $this->assertStringContainsString('/history?', $href);
        $this->assertStringContainsString('range=7', $href);
        $this->assertStringContainsString('user=Alice', $href);
        $this->assertStringContainsString('search=Dune', $href);
    }
}
