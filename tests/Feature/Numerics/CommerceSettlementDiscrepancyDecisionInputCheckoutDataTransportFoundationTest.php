<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Release\ReleasePreflightInspector;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionInputCheckoutDataTransportFoundationTest extends TestCase
{
    public function test_policy_declares_checkout_data_transport_without_downstream_runtime_effect(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_input_checkout_data_runtime_wired'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_input_request_runtime_wired'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_input_controller_runtime_wired'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_manager_runtime_wired'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_business_mutation_authorized'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_keep_reference_allows_sale_confirmation'
            ],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_checkout_data_preserves_explicit_keep_reference_input(): void
    {
        $input =
            CommerceSettlementDiscrepancyDecisionInput::keepReference(
                'Preserve the system-derived total and require settlement review.'
            );

        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey:
                'test:checkout-data-decision-input:00000000-0000-0000-0000-000000000001',
            payments: [],
            settlementDiscrepancyDecisionInput: $input,
        );

        $this->assertSame(
            $input,
            $data->settlementDiscrepancyDecisionInput,
        );
    }

    public function test_checkout_data_remains_backward_compatible_when_decision_input_is_absent(): void
    {
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey:
                'test:checkout-data-decision-input:00000000-0000-0000-0000-000000000002',
            payments: [],
        );

        $this->assertNull($data->settlementDiscrepancyDecisionInput);
    }

    public function test_manager_remains_opaque_to_decision_input(): void
    {
        $paths = [
            app_path('Domain/Commerce/CommerceCheckoutManager.php'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'CommerceSettlementDiscrepancyDecisionInput',
                $source,
            );
            $this->assertStringNotContainsString(
                'settlementDiscrepancyDecisionInput',
                $source,
            );
        }
    }
}
