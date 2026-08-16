<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoPointRefundAdapter;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoPagoPointRefundCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_refund_contract_is_append_only_compatible_and_does_not_rewrite_reference_snapshot(): void
    {
        $registry =
            app(
                FinancialProviderCompatibilityRegistry::class
            );

        [$legacy] =
            $registry->seedReferenceRegistry();

        $refundContract =
            $registry
                ->registerMercadoPagoPointRefundContractV1();

        $refundContractRetry =
            $registry
                ->registerMercadoPagoPointRefundContractV1();

        $this->assertSame(
            $refundContract->id,
            $refundContractRetry->id
        );

        $this->assertNotSame(
            $legacy->id,
            $refundContract->id
        );

        $this->assertSame(
            FinancialProviderCompatibilityStatus::Compatible,
            $refundContract
                ->compatibility_status
        );

        $this->assertFalse(
            $refundContract
                ->migration_required
        );

        $this->assertSame(
            MercadoPagoPointRefundAdapter::class,
            $refundContract
                ->adapter_class
        );

        $legacyRefund =
            $legacy
                ->capabilities
                ->firstWhere(
                    'capability',
                    FinancialProviderCapability::Refund
                );

        $newRefund =
            $refundContract
                ->capabilities
                ->firstWhere(
                    'capability',
                    FinancialProviderCapability::Refund
                );

        $this->assertNotNull(
            $legacyRefund
        );

        $this->assertNotNull(
            $newRefund
        );

        $this->assertSame(
            FinancialProviderCompatibilityStatus::Unknown,
            $legacyRefund
                ->compatibility_status
        );

        $this->assertSame(
            FinancialProviderCompatibilityStatus::Compatible,
            $newRefund
                ->compatibility_status
        );

        $this->assertTrue(
            $newRefund->required
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            3
        );
    }
}
