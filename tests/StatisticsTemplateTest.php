<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsTemplateTest extends TestCase
{
    public function testStatisticsPageUsesTheHistoryFilterBar(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $bar = file_get_contents(TEMPLATES_DIR . '/_filter_bar.twig');

        $this->assertIsString($template);
        $this->assertIsString($bar);
        $this->assertStringContainsString('_filter_bar.twig', $template);
        $this->assertStringContainsString('name="library"', $bar);
        $this->assertStringContainsString('Last 7 days', $bar);
        $this->assertStringNotContainsString('segmented-control', $template);
        $this->assertStringContainsString('stats.monthHeatmap.visible', $template);
        $this->assertStringContainsString('Watch time by month', $template);
    }

    public function testRequestWatchSectionIsGatedOnSeerr(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('seerr_enabled and stats.requestWatch.visible', $template);
        $this->assertStringContainsString('Request Watch Rate', $template);
    }
}
