<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class SalePaymentOverlayTest extends TestCase
{
    public function test_sale_uses_independent_apb_payment_terminal_and_shortcuts(): void
    {
        $sale = file_get_contents(
            resource_path('views/commerce-sales/create.blade.php')
        );
        $layout = file_get_contents(
            resource_path('views/layouts/app.blade.php')
        );

        $this->assertIsString($sale);
        $this->assertIsString($layout);

        foreach ([
            'data-sale-payment-overlay',
            'data-sale-payment-launcher',
            'data-sale-payment-review',
            'data-sale-payment-reconciliation',
            'data-sale-payment-method-picker',
            'data-sale-payment-financial-account',
            'data-sale-payment-no-financial-accounts',
            'Cuenta destino',
            'financial_account_id',
            'financialAccountOptions()',
            'financialAccountLabel(payment.financial_account_id)',
            'No hay cuentas financieras activas',
            'Destino:',
            'data-sale-payment-structured-evidence',
            'data-sale-payment-evidence-source-guidance',
            'Automática / API',
            'Pendiente de integración',
            'Respaldo manual',
            'Disponible hoy',
            'El operador no deberá transcribirla.',
            'Carga manual de respaldo',
            'card_brand',
            'card_network',
            'card_last4',
            'installments',
            'processor',
            'external_operation_id',
            'authorization_code',
            'provider_status',
            'Snapshot declarado al cobrar',
            'paymentEvidenceSummary(payment)',
            'Nunca PAN/CVV',
            'paymentOverlayOpen:',
            'paymentReviewOpen:',
            'paymentTotalMinor()',
            'saleTotalMinor()',
            'serviceCurrencyMatches()',
            'syncAutomaticPayment()',
            'adjustLastPaymentToBalance()',
            'handleSaleShortcut(event)',
            "event.key === 'F3'",
            "event.key === 'F7'",
            'F1 Nueva venta · F3 Artículos · F7 Cobro',
            '¿Cómo paga el cliente?',
            'Elegí el medio explícitamente',
            'SRCM no presupone Efectivo',
            'FALTA',
            'EXCEDE',
            'PAGO EXACTO',
            "paymentStatus() === 'incomplete'",
            'FALTAN DATOS DEL PAGO',
            'Ajustar último al saldo',
            'Manual: SRCM no lo reescribe silenciosamente.',
            'Dinero entregado',
            'Vuelto:',
            'tendered_amount',
            'data-sale-cash-tender',
            'data-sale-cash-change',
            'data-sale-cash-tender-review',
            'cashTenderedMinor(payment)',
            'paymentChangeMinor(payment)',
            'markPaymentTenderManual(payment)',
            'Confirmar cobro',
            'Enter y F7 no confirman.',
            'backPaymentLevel()',
            '@keydown.escape.window',
            "paymentReviewOpen ? 'Volver al cobro' : 'Volver a venta'",
            'Esc vuelve un nivel',
            'data-sale-payment-review-back',
        ] as $marker) {
            $this->assertStringContainsString($marker, $sale);
        }

        $this->assertStringContainsString(
            '@keydown.enter.prevent',
            $sale
        );
        $this->assertStringContainsString(
            'data-sale-final-submit',
            $sale
        );
        $this->assertStringContainsString(
            '$paymentRows = [];',
            $sale
        );

        $this->assertStringNotContainsString(
            'paymentsOpen: false,',
            $sale
        );
        $this->assertStringNotContainsString(
            "method: 'cash',",
            $sale
        );
        $this->assertStringNotContainsString(
            'payments[${index}][card_number]',
            $sale
        );
        $this->assertStringNotContainsString(
            'payments[${index}][cvv]',
            $sale
        );

        $this->assertStringContainsString(
            'data-srcm-global-f1-shortcut',
            $layout
        );
        $this->assertStringContainsString(
            "event.key !== 'F1'",
            $layout
        );
        $this->assertStringContainsString(
            'Hay una venta en curso.',
            $layout
        );
    }
}