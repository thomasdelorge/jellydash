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
        $this->assertStringContainsString('data-history-dialog', $template);
        $this->assertStringContainsString('/assets/js/history.js?v=20260814-edit', $template);
        $this->assertStringContainsString('data-history-confirm', $template);

        $row = file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');
        $this->assertIsString($row);
        $this->assertStringContainsString('data-history-id="{{ play.id }}"', $row);
        $this->assertStringContainsString('data-history-edit="{{ play.id }}"', $row);
        $this->assertStringContainsString('data-history-delete-row="{{ play.id }}"', $row);
    }
}
