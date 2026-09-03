<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Release\ReleasePreflightInspector;
use App\Http\Requests\StoreCommerceSaleRequest;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionInputHttpTransportFoundationTest extends TestCase
{
    public function test_policy_declares_http_transport_without_manager_runtime_effect(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertTrue(
            $policy['commerce_settlement_decision_input_request_runtime_wired'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_decision_input_controller_runtime_wired'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_decision_input_checkout_data_runtime_wired'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_decision_input_manager_runtime_wired'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_decision_input_business_mutation_authorized'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_decision_input_keep_reference_allows_sale_confirmation'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_request_exposes_optional_typed_keep_reference_input(): void
    {
        $request = StoreCommerceSaleRequest::create('/', 'POST');

        $this->assertNull(
            $request->settlementDiscrepancyDecisionInput(),
        );

        $request->merge([
            'settlement_discrepancy_decision' => 'KEEP_REFERENCE',
            'settlement_discrepancy_reason' =>
                'Preserve the system-derived total and require settlement review.',
        ]);

        $input = $request->settlementDiscrepancyDecisionInput();

        $this->assertInstanceOf(
            CommerceSettlementDiscrepancyDecisionInput::class,
            $input,
        );
        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference,
            $input->decision,
        );
        $this->assertSame(
            'Preserve the system-derived total and require settlement review.',
            $input->reason,
        );
    }

    public function test_request_typed_accessor_fails_closed_for_noncanonical_or_blocked_input(): void
    {
        foreach ([
            ['ACCEPT_OBSERVED', 'Explicit but blocked decision.'],
            ['KEEP_REFERENCE', ' leading'],
            ['KEEP_REFERENCE', 'trailing '],
            ['KEEP_REFERENCE', "control\ncharacter"],
            ['KEEP_REFERENCE', str_repeat('é', 1025)],
        ] as [$decision, $reason]) {
            $request = StoreCommerceSaleRequest::create('/', 'POST', [
                'settlement_discrepancy_decision' => $decision,
                'settlement_discrepancy_reason' => $reason,
            ]);

            try {
                $request->settlementDiscrepancyDecisionInput();

                $this->fail(
                    'Invalid HTTP settlement discrepancy intent was accepted.'
                );
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_request_rules_enforce_pairing_and_keep_reference_only(): void
    {
        $source = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "'required_with:settlement_discrepancy_reason'",
            $source,
        );
        $this->assertStringContainsString(
            "'required_with:settlement_discrepancy_decision'",
            $source,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyDecisionInput::',
            $source,
        );
        $this->assertStringContainsString(
            'AUTHORIZED_DECISION_VALUES',
            $source,
        );
        $this->assertStringContainsString(
            "'max:2048'",
            $source,
        );
        $this->assertStringNotContainsString(
            "trim((string) \$this->input('settlement_discrepancy_reason'",
            $source,
        );
    }

    public function test_controller_transports_typed_accessor_and_manager_remains_opaque(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );

        $this->assertIsString($controller);
        $this->assertIsString($manager);
        $this->assertStringContainsString(
            'settlementDiscrepancyDecisionInput:',
            $controller,
        );
        $this->assertStringContainsString(
            '$request->settlementDiscrepancyDecisionInput()',
            $controller,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionInput',
            $manager,
        );
        $this->assertStringNotContainsString(
            'settlementDiscrepancyDecisionInput',
            $manager,
        );
    }
}
