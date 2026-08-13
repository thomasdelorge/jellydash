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
            'Showing {{ summary.from }}-{{ summary.to }} of {{ summary.filtered_total }} plays',
            $template
        );
        $this->assertStringContainsString(
            '{{ summary.from }}-{{ summary.to }} <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString('history/_pager.twig', $template);
        $this->assertStringContainsString('data-history-dialog', $template);
        $this->assertStringContainsString('data-history-clamp', $template);
        $this->assertStringContainsString('/assets/js/history.js?v=20260813-confirm', $template);
        $this->assertStringContainsString('data-history-confirm', $template);

        $row = file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');
        $this->assertIsString($row);
        $this->assertStringContainsString('data-history-id="{{ play.id }}"', $row);
        $this->assertStringContainsString('data-history-edit="{{ play.id }}"', $row);
        $this->assertStringContainsString('data-history-delete-row="{{ play.id }}"', $row);
        $this->assertStringContainsString('play.exceedsRuntime', $row);
        $this->assertStringContainsString('_avatar.twig', $row);
    }
}
