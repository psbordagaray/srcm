<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class SaleExplicitSubmitGuardTest extends TestCase
{
    public function test_sale_form_blocks_implicit_enter_submission_and_keeps_explicit_final_submit(): void
    {
        $source = file_get_contents(resource_path('views/commerce-sales/create.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('data-sale-explicit-submit-only', $source);
        $this->assertStringContainsString(
            '@keydown.enter="if (! [\'TEXTAREA\', \'BUTTON\', \'SELECT\'].includes($event.target.tagName)) { $event.preventDefault() }"',
            $source
        );

        // Enter operativos ya existentes: búsqueda y cantidad.
        $this->assertStringContainsString('@keydown.enter.prevent="commitArticleSearch()"', $source);
        $this->assertStringContainsString('@keydown.enter.prevent="addOrUpdateProduct()"', $source);

        // La operación inmutable sigue requiriendo el botón final explícito.
        $this->assertStringContainsString('type="submit"', $source);
        $this->assertStringContainsString('data-sale-final-submit', $source);
        $this->assertStringContainsString('Confirmar cobro', $source);
        $this->assertStringContainsString('@keydown.enter.prevent', $source);
        $this->assertStringContainsString('data-sale-payment-overlay', $source);
    }
}