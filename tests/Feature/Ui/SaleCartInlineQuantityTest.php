<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class SaleCartInlineQuantityTest extends TestCase
{
    public function test_cart_quantity_is_editable_inline_without_implicit_sale_submit(): void
    {
        $source = file_get_contents(
            resource_path('views/commerce-sales/create.blade.php')
        );

        $this->assertIsString($source);

        $this->assertStringContainsString(
            'cartQuantityError:',
            $source
        );
        $this->assertStringContainsString(
            'cartStep(productId)',
            $source
        );
        $this->assertStringContainsString(
            'cartLineAvailable(index)',
            $source
        );
        $this->assertStringContainsString(
            'setCartQuantity(index, value)',
            $source
        );
        $this->assertStringContainsString(
            'adjustCartQuantity(index, direction)',
            $source
        );

        $this->assertStringContainsString(
            'data-sale-cart-quantity-control',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-cart-quantity-minus',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-cart-quantity-input',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-cart-quantity-plus',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-cart-quantity-error',
            $source
        );

        $this->assertStringContainsString(
            '@keydown.enter.prevent="$event.target.blur()"',
            $source
        );
        $this->assertStringContainsString(
            ':max="cartLineAvailable(index)"',
            $source
        );

        // Debe conservar límites y reglas ya existentes.
        $this->assertStringContainsString(
            'normalizedQuantity(value, productId)',
            $source
        );
        $this->assertStringContainsString(
            'rawAvailableAt(productId, condition, locationId)',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-explicit-submit-only',
            $source
        );
        $this->assertStringContainsString(
            'data-sale-final-submit',
            $source
        );

        // La cantidad enviada al backend sigue viniendo de line.quantity.
        $this->assertStringContainsString(
            ':name="`product_lines[${index}][quantity]`"',
            $source
        );
        $this->assertStringContainsString(
            ':value="line.quantity"',
            $source
        );
    }
}