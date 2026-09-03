<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionInputFoundationTest extends TestCase
{
    public function test_policy_declares_decision_input_foundation_without_runtime_transport(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::FOUNDATION_VERSION,
            $policy[
                'commerce_settlement_decision_input_foundation_version'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::SCHEMA,
            $policy['commerce_settlement_decision_input_schema'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::class,
            $policy['commerce_settlement_decision_input_class'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::
                AUTHORIZED_DECISION_VALUES,
            $policy[
                'commerce_settlement_decision_input_authorized_values'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::
                BLOCKED_DECISION_VALUES,
            $policy[
                'commerce_settlement_decision_input_blocked_values'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::REASON_MAX_BYTES,
            $policy['commerce_settlement_decision_input_reason_max_bytes'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_decision_input_reason_required'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_decision_input_reason_trimmed'],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_control_characters_allowed'
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
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_request_runtime_wired'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_controller_runtime_wired'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_checkout_data_runtime_wired'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_input_manager_runtime_wired'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionInput::
                RUNTIME_WIRING_STATUS,
            $policy[
                'commerce_settlement_decision_input_runtime_wiring_status'
            ],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_keep_reference_creates_explicit_typed_intent_without_evidence_or_business_authority(): void
    {
        $input =
            CommerceSettlementDiscrepancyDecisionInput::keepReference(
                'Preserve the system-derived total and require settlement review.'
            );

        $array = $input->toArray();

        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference,
            $input->decision,
        );
        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference->value,
            $array['decision'],
        );
        $this->assertTrue($array['explicit_decision']);
        $this->assertFalse($array['is_decision_evidence']);
        $this->assertFalse(
            $array['requires_runtime_discrepancy_evidence'],
        );
        $this->assertFalse($array['business_mutation_authorized']);
        $this->assertFalse(
            $array['keep_reference_allows_sale_confirmation'],
        );
        $this->assertFalse($array['override_authorization_required']);
        $this->assertFalse($array['persists_audit']);
        $this->assertFalse($array['request_runtime_wired']);
        $this->assertFalse($array['controller_runtime_wired']);
        $this->assertFalse($array['checkout_data_runtime_wired']);
        $this->assertFalse($array['manager_runtime_wired']);
    }

    public function test_reason_is_explicit_trimmed_bounded_and_free_of_control_characters(): void
    {
        foreach (
            [
                '',
                ' leading',
                'trailing ',
                "control\ncharacter",
                str_repeat('x', 2049),
            ] as $invalidReason
        ) {
            try {
                CommerceSettlementDiscrepancyDecisionInput::
                    keepReference($invalidReason);

                $this->fail(
                    'Invalid Commerce settlement decision input reason was accepted.'
                );
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Commerce settlement decision input reason must be explicit, bounded and free of control characters.',
                    $exception->getMessage(),
                );
            }
        }

        $max = str_repeat('x', 2048);
        $input =
            CommerceSettlementDiscrepancyDecisionInput::keepReference(
                $max
            );

        $this->assertSame($max, $input->reason);
    }

    public function test_accept_observed_and_all_runtime_transport_remain_blocked(): void
    {
        $this->assertFalse(
            method_exists(
                CommerceSettlementDiscrepancyDecisionInput::class,
                'acceptObserved',
            ),
        );
        $this->assertSame(
            ['KEEP_REFERENCE'],
            CommerceSettlementDiscrepancyDecisionInput::
                AUTHORIZED_DECISION_VALUES,
        );
        $this->assertSame(
            ['ACCEPT_OBSERVED'],
            CommerceSettlementDiscrepancyDecisionInput::
                BLOCKED_DECISION_VALUES,
        );

        $paths = [
            app_path('Domain/Commerce/CommerceCheckoutManager.php'),
            app_path('Domain/Commerce/CommerceCheckoutData.php'),
            app_path('Http/Requests/StoreCommerceSaleRequest.php'),
            app_path('Http/Controllers/CommerceSaleController.php'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'CommerceSettlementDiscrepancyDecisionInput',
                $source,
            );
        }
    }
}
