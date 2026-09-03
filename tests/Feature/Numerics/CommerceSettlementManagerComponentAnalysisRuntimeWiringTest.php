<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceSettlementComponentAnalysis;
use App\Domain\Commerce\CommerceSettlementComponentAnalyzer;
use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Enums\CommercePaymentMethod;
use DomainException;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementManagerComponentAnalysisRuntimeWiringTest extends TestCase
{
    public function test_runtime_exception_preserves_exact_hard_fail_message_and_complete_component_coverage(): void
    {
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'runtime-analysis-test-1',
            payments: [
                new CommercePaymentData(
                    method: CommercePaymentMethod::Cash,
                    amountMinor: 1234,
                    settlementComponentEvidence:
                        CommerceSettlementComponentEvidence::payment(
                            index: 0,
                            rawHumanInput: '12.34',
                            originalCanonicalValue: '12.34',
                            minorValue: 1234,
                        ),
                ),
                new CommercePaymentData(
                    method: CommercePaymentMethod::Cash,
                    amountMinor: 500,
                ),
            ],
            receivableAmountMinor: 1000,
            receivableSettlementComponentEvidence:
                CommerceSettlementComponentEvidence::receivable(
                    rawHumanInput: '10.00',
                    originalCanonicalValue: '10.00',
                    minorValue: 1000,
                ),
        );

        $exception =
            CommerceSettlementDiscrepancyException::fromCheckoutData(
                data: $data,
                systemTotalMinor: 3634,
                settledTotalMinor: 2734,
                analyzer: new CommerceSettlementComponentAnalyzer(),
            );

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $exception->getMessage(),
        );
        $this->assertSame(3634, $exception->systemTotalMinor);
        $this->assertSame(2734, $exception->settledTotalMinor);
        $this->assertSame(
            [
                'payments.0.amount',
                'payments.1.amount',
                'receivable_amount',
            ],
            $exception->observedComponentIds,
        );
        $this->assertCount(2, $exception->componentAnalyses);
        $this->assertSame(
            ['payments.1.amount'],
            $exception->missingTransportEvidenceComponentIds,
        );

        $analysisIds = array_map(
            static fn (
                CommerceSettlementComponentAnalysis $analysis
            ): string => $analysis->sourceEvidence->componentId,
            $exception->componentAnalyses,
        );

        $this->assertSame(
            ['payments.0.amount', 'receivable_amount'],
            $analysisIds,
        );

        $audit = $exception->toArray();

        $this->assertSame(
            CommerceSettlementDiscrepancyException::SCHEMA,
            $audit['schema'],
        );
        $this->assertTrue(
            $audit['aggregate_discrepancy_unresolved'],
        );
        $this->assertFalse($audit['authorizes_correction']);
        $this->assertFalse(
            $audit['authorizes_keep_reference_decision'],
        );
        $this->assertFalse(
            $audit['authorizes_accept_observed'],
        );
        $this->assertFalse($audit['authorizes_override']);
        $this->assertFalse($audit['persists_audit']);
        $this->assertFalse(
            $audit['controller_special_handling'],
        );
    }

    public function test_missing_transport_evidence_is_recorded_without_fabrication(): void
    {
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'runtime-analysis-test-2',
            payments: [
                new CommercePaymentData(
                    method: CommercePaymentMethod::Cash,
                    amountMinor: 1000,
                ),
            ],
        );

        $exception =
            CommerceSettlementDiscrepancyException::fromCheckoutData(
                data: $data,
                systemTotalMinor: 2000,
                settledTotalMinor: 1000,
                analyzer: new CommerceSettlementComponentAnalyzer(),
            );

        $this->assertSame([], $exception->componentAnalyses);
        $this->assertSame(
            ['payments.0.amount'],
            $exception->observedComponentIds,
        );
        $this->assertSame(
            ['payments.0.amount'],
            $exception->missingTransportEvidenceComponentIds,
        );
    }

    public function test_runtime_exception_factory_rejects_healthy_or_non_positive_aggregate_context(): void
    {
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'runtime-analysis-test-3',
            payments: [
                new CommercePaymentData(
                    method: CommercePaymentMethod::Cash,
                    amountMinor: 1000,
                    settlementComponentEvidence:
                        CommerceSettlementComponentEvidence::payment(
                            index: 0,
                            rawHumanInput: '10.00',
                            originalCanonicalValue: '10.00',
                            minorValue: 1000,
                        ),
                ),
            ],
        );

        $analyzer = new CommerceSettlementComponentAnalyzer();

        foreach (
            [
                [1000, 1000],
                [0, 1000],
                [1000, 0],
            ]
            as [$systemTotalMinor, $settledTotalMinor]
        ) {
            try {
                CommerceSettlementDiscrepancyException::
                    fromCheckoutData(
                        data: $data,
                        systemTotalMinor: $systemTotalMinor,
                        settledTotalMinor: $settledTotalMinor,
                        analyzer: $analyzer,
                    );

                $this->fail(
                    'Healthy or non-positive aggregate context must not enter runtime component analysis.'
                );
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manager_wiring_is_ephemeral_and_controller_remains_generic_domain_exception_consumer(): void
    {
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($manager);
        $this->assertIsString($controller);

        $this->assertStringContainsString(
            'CommerceSettlementComponentAnalyzer $settlementComponentAnalyzer',
            $manager,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyException::',
            $manager,
        );
        $this->assertStringContainsString(
            'fromCheckoutData(',
            $manager,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyDecisionException::',
            $manager,
        );
        $this->assertStringContainsString(
            'settlementDiscrepancyDecisionInput',
            $manager,
        );
        $this->assertStringContainsString(
            'if ($total <= 0 || $settledTotal !== $total) {',
            $manager,
        );
        $this->assertStringContainsString(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $manager,
        );

        $this->assertStringContainsString(
            'catch (DomainException $exception)',
            $controller,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyException',
            $controller,
        );
    }

    public function test_policy_declares_manager_analysis_wiring_as_non_authoritative_and_non_persistent(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertSame(
            CommerceSettlementDiscrepancyException::SCHEMA,
            $policy[
                'commerce_settlement_component_analysis_runtime_exception_schema'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::class,
            $policy[
                'commerce_settlement_component_analysis_runtime_exception_class'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $policy[
                'commerce_settlement_component_analysis_runtime_exception_message'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::
                RUNTIME_WIRING_STATUS,
            $policy[
                'commerce_settlement_component_analysis_runtime_wiring_status'
            ],
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_component_analysis_runtime_hard_fail_preserved'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_component_analysis_runtime_controller_special_handling'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_component_analysis_runtime_audit_persistence'
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
    }
}
