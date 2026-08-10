<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class SaleCartPaperPanelTest extends TestCase
{
    public function test_sale_cart_is_a_high_contrast_operational_paper_panel(): void
    {
        $source = file_get_contents(resource_path('views/commerce-sales/create.blade.php'));

        $this->assertIsString($source);

        $this->assertStringContainsString('data-sale-cart-paper-style', $source);
        $this->assertStringContainsString('data-sale-cart-paper-panel', $source);
        $this->assertStringContainsString('data-sale-cart-header', $source);
        $this->assertStringContainsString('data-sale-cart-title', $source);
        $this->assertStringContainsString('data-sale-cart-viewport', $source);
        $this->assertStringContainsString('data-sale-cart-table', $source);
        $this->assertStringContainsString('data-sale-cart-thead', $source);
        $this->assertStringContainsString('data-sale-cart-tbody', $source);
        $this->assertStringContainsString('data-sale-cart-row', $source);

        $this->assertStringContainsString(
            "content: 'Hoja operativa';",
            $source
        );

        $this->assertStringContainsString(
            'linear-gradient(180deg, rgba(255,255,255,1) 0%, rgba(248,250,252,1) 100%)',
            $source
        );

        $this->assertStringContainsString(
            '[data-sale-cart-paper-panel] [data-sale-cart-row]:nth-child(even)',
            $source
        );

        $this->assertStringContainsString(
            'border-color: #dbe4ee !important;',
            $source
        );

        $this->assertStringContainsString('data-sale-cart-edit', $source);
        $this->assertStringContainsString('data-sale-cart-remove', $source);
        $this->assertStringContainsString('<div data-sale-cart-summary', $source);
        $this->assertStringContainsString('background: #e7edf4 !important;', $source);
        $this->assertStringContainsString('color: #334155 !important;', $source);
        $this->assertStringContainsString('color: #0f3d5e !important;', $source);
        $this->assertStringContainsString('[data-sale-cart-summary] > span:last-child', $source);

        // El hardening previo debe seguir presente.
        $this->assertStringContainsString('data-sale-explicit-submit-only', $source);
        $this->assertStringContainsString('data-sale-final-submit', $source);
    }
}