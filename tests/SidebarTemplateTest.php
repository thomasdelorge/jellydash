<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SidebarTemplateTest extends TestCase
{
    public function testSidebarExposesLabeledNavItemsWithoutFoldControl(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/_sidebar.twig');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('data-sidebar-fold', $template);
        $this->assertStringContainsString('class="nav-label"', $template);
        $this->assertStringContainsString('data-label="Now Playing"', $template);
        $this->assertStringContainsString('data-label="History"', $template);
        $this->assertStringContainsString('data-label="Statistics"', $template);
        $this->assertStringContainsString('data-label="Libraries"', $template);
    }

    public function testShellDoesNotForceCollapsedSidebarWithScript(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/_shell.twig');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('sidebar-collapsed', $template);
        $this->assertStringContainsString('/assets/css/dashboard.css?v=20260814-rail', $template);
        $this->assertStringContainsString('/assets/js/nav.js?v=20260814-rail', $template);
    }
}
