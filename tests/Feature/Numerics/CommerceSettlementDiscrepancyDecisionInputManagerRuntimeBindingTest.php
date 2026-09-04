<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceSettlementComponentAnalyzer;
use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Release\ReleasePreflightInspector;
use App\Enums\CommercePaymentMethod;
use DomainException;
use ReflectionMethod;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionInputManagerRuntimeBindingTest extends TestCase
{
    public function test_keep_reference_input_binds_exact_runtime_evidence_and_remains_hard_fail(): void
    {
        $runtimeEvidence = $this->runtimeEvidence();
        $input =
            CommerceSettlementDiscrepancyDecisionInput::keepReference(
                'Settlement reviewed; preserve the system-derived total.'
            );

        $exception =
            CommerceSettlementDiscrepancyDecisionException::fromInput(
                runtimeEvidence: $runtimeEvidence,
                input: $input,
            );

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $exception->getMessage(),
        );
        $this->assertSame(
            $runtimeEvidence,
            $exception->runtimeEvidence,
        );
        $this->assertSame(
            $runtimeEvidence,
            $exception->decisionEvidence->runtimeEvidence,
        );
        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference,
            $exception->decisionEvidence->decision,
        );
        $this->assertSame(
            10000,
            $exception->decisionEvidence->finalValueMinor,
        );
        $this->assertSame(
            $input->reason,
            $exception->decisionEvidence->reason,
        );

        $array = $exception->toArray();

        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionException::SCHEMA,
            $array['schema'],
        );
        $this->assertSame(10000, $array['reference_value_minor']);
        $this->assertSame(9000, $array['observed_value_minor']);
        $this->assertSame('KEEP_REFERENCE', $array['decision']);
        $this->assertSame(10000, $array['final_value_minor']);
        $this->assertTrue($array['aggregate_discrepancy_unresolved']);
        $this->assertTrue($array['settlement_review_required']);
        $this->assertTrue($array['hard_fail_preserved']);
        $this->assertFalse($array['sale_confirmation_authorized']);
        $this->assertFalse($array['automatic_correction']);
        $this->assertFalse($array['business_mutation_authorized']);
        $this->assertFalse($array['persists_audit']);
        $this->assertTrue($array['controller_special_handling']);
    }

    public function test_manager_preserves_existing_no_input_exception_and_only_binds_positive_mismatch_input(): void
    {
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );

        $this->assertIsString($manager);
        $this->assertStringContainsString(
            '$runtimeEvidence =',
            $manager,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyException::',
            $manager,
        );
        $this->assertStringContainsString(
            'settlementDiscrepancyDecisionInput',
            $manager,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyDecisionException::',
            $manager,
        );
        $this->assertStringContainsString(
            'fromInput(',
            $manager,
        );
        $this->assertStringContainsString(
            'throw $runtimeEvidence;',
            $manager,
        );
        $this->assertStringNotContainsString(
            'AuditRecorder',
            $manager,
        );
    }

    public function test_manager_normalize_and_business_fingerprint_remain_opaque_to_decision_input(): void
    {
        $method = new ReflectionMethod(
            CommerceCheckoutManager::class,
            'normalize',
        );
        $source = file(
            $method->getFileName(),
            FILE_IGNORE_NEW_LINES,
        );

        $this->assertIsArray($source);

        $slice = array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );
        $normalizeSource = implode("\n", $slice);

        $this->assertStringNotContainsString(
            'settlementDiscrepancyDecisionInput',
            $normalizeSource,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionInput',
            $normalizeSource,
        );
    }

    public function test_policy_declares_manager_binding_without_keep_reference_business_effect(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_input_manager_runtime_wired'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_aggregate_decision_manager_runtime_wired'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_component_analysis_runtime_decision_wiring'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_component_analysis_runtime_keep_reference_authorized'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_component_analysis_runtime_accept_observed_authorized'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionException::class,
            $policy[
                'commerce_settlement_decision_runtime_exception_class'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionException::SCHEMA,
            $policy[
                'commerce_settlement_decision_runtime_exception_schema'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_runtime_hard_fail_preserved'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_runtime_sale_confirmation_authorized'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_decision_runtime_audit_persistence'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_runtime_controller_special_handling'
            ],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_accept_observed_remains_blocked_and_controller_persists_review_only(): void
    {
        $this->assertFalse(
            method_exists(
                CommerceSettlementDiscrepancyDecisionInput::class,
                'acceptObserved',
            ),
        );
        $this->assertFalse(
            method_exists(
                CommerceSettlementDiscrepancyDecisionEvidence::class,
                'acceptObserved',
            ),
        );

        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($controller);
        $this->assertStringContainsString(
            'catch (CommerceSettlementDiscrepancyDecisionException $exception)',
            $controller,
        );
        $this->assertStringContainsString(
            'CommerceSettlementReviewRecorder',
            $controller,
        );
        $this->assertStringContainsString(
            'catch (DomainException $exception)',
            $controller,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementReviewRecorder',
            file_get_contents(
                app_path('Domain/Commerce/CommerceCheckoutManager.php')
            ),
        );
    }

    private function runtimeEvidence(): CommerceSettlementDiscrepancyException
    {
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey:
                'test:manager-decision-binding:00000000-0000-0000-0000-000000000001',
            payments: [
                new CommercePaymentData(
                    method: CommercePaymentMethod::Cash,
                    amountMinor: 9000,
                    settlementComponentEvidence:
                        CommerceSettlementComponentEvidence::payment(
                            index: 0,
                            rawHumanInput: '90.00',
                            originalCanonicalValue: '90.00',
                            minorValue: 9000,
                        ),
                ),
            ],
        );

        return CommerceSettlementDiscrepancyException::fromCheckoutData(
            data: $data,
            systemTotalMinor: 10000,
            settledTotalMinor: 9000,
            analyzer: new CommerceSettlementComponentAnalyzer(),
        );
    }
}
