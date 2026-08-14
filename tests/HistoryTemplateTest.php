<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoryTemplateTest extends TestCase
{
    public function testFooterUsesFilteredResultTotal(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            '{{ summary.shown }} <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString('_filter_bar.twig', $template);
        $this->assertStringContainsString('show_search', file_get_contents(TEMPLATES_DIR . '/_filter_bar.twig') ?: '');
    }
}
