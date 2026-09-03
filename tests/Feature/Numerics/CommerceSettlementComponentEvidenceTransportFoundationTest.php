<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Release\ReleasePreflightInspector;
use App\Enums\CommercePaymentMethod;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementComponentEvidenceTransportFoundationTest extends TestCase
{
    public function test_policy_declares_transport_foundation_without_manager_analysis(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy['commerce_settlement_component_evidence_transport_foundation_version'],
        );
        $this->assertSame(
            CommercePaymentData::class,
            $policy['commerce_settlement_payment_evidence_transport_carrier_class'],
        );
        $this->assertSame(
            CommerceCheckoutData::class,
            $policy['commerce_settlement_receivable_evidence_transport_carrier_class'],
        );
        $this->assertSame(
            'settlementComponentEvidence',
            $policy['commerce_settlement_payment_evidence_transport_field'],
        );
        $this->assertSame(
            'receivableSettlementComponentEvidence',
            $policy['commerce_settlement_receivable_evidence_transport_field'],
        );
        $this->assertSame(
            'controller_constructed_residual_null',
            $policy['commerce_settlement_component_evidence_transport_residual_policy'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_evidence_transport_in_normalized_business_payload'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_evidence_transport_in_business_fingerprint'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_evidence_transport_invokes_analyzer'],
        );
        $this->assertSame(
            'foundation_carrier_wired_manager_analysis_not_wired',
            $policy['commerce_settlement_component_evidence_transport_runtime_status'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_payment_carrier_preserves_source_evidence_without_residual(): void
    {
        $evidence = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '12,34',
            originalCanonicalValue: '12.34',
            minorValue: 1234,
        );

        $payment = new CommercePaymentData(
            method: CommercePaymentMethod::Cash,
            amountMinor: 1234,
            settlementComponentEvidence: $evidence,
        );

        $this->assertSame(
            $evidence,
            $payment->settlementComponentEvidence,
        );
        $this->assertFalse(
            $payment->settlementComponentEvidence
                ->hasConditionalResidualCandidate(),
        );
        $this->assertSame(
            'payments.0.amount',
            $payment->settlementComponentEvidence->componentId,
        );
        $this->assertSame(
            '12,34',
            $payment->settlementComponentEvidence->rawHumanInput,
        );
        $this->assertSame(
            '12.34',
            $payment->settlementComponentEvidence
                ->originalCanonicalValue,
        );
    }

    public function test_payment_carrier_fails_closed_on_minor_mismatch_or_residual(): void
    {
        $mismatch = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '12,34',
            originalCanonicalValue: '12.34',
            minorValue: 1234,
        );

        try {
            new CommercePaymentData(
                method: CommercePaymentMethod::Cash,
                amountMinor: 1235,
                settlementComponentEvidence: $mismatch,
            );

            $this->fail('Minor mismatch must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $withResidual =
            CommerceSettlementComponentEvidence::payment(
                index: 0,
                rawHumanInput: '12,34',
                originalCanonicalValue: '12.34',
                minorValue: 1234,
                conditionalResidualReferenceValue: '13.24',
            );

        $this->expectException(InvalidArgumentException::class);

        new CommercePaymentData(
            method: CommercePaymentMethod::Cash,
            amountMinor: 1234,
            settlementComponentEvidence: $withResidual,
        );
    }

    public function test_checkout_carrier_binds_payment_position_and_receivable_exactly(): void
    {
        $paymentEvidence =
            CommerceSettlementComponentEvidence::payment(
                index: 0,
                rawHumanInput: '10,00',
                originalCanonicalValue: '10.00',
                minorValue: 1000,
            );
        $payment = new CommercePaymentData(
            method: CommercePaymentMethod::Cash,
            amountMinor: 1000,
            settlementComponentEvidence: $paymentEvidence,
        );
        $receivableEvidence =
            CommerceSettlementComponentEvidence::receivable(
                rawHumanInput: '5.00',
                originalCanonicalValue: '5.00',
                minorValue: 500,
            );

        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey:
                'service-ui:commerce-sale:00000000-0000-0000-0000-000000000001',
            payments: [$payment],
            receivableAmountMinor: 500,
            receivableSettlementComponentEvidence:
                $receivableEvidence,
        );

        $this->assertSame(
            $receivableEvidence,
            $data->receivableSettlementComponentEvidence,
        );
        $this->assertFalse(
            $data->receivableSettlementComponentEvidence
                ->hasConditionalResidualCandidate(),
        );
    }

    public function test_checkout_carrier_rejects_payment_component_id_that_does_not_match_position(): void
    {
        $payment = new CommercePaymentData(
            method: CommercePaymentMethod::Cash,
            amountMinor: 1000,
            settlementComponentEvidence:
                CommerceSettlementComponentEvidence::payment(
                    index: 1,
                    rawHumanInput: '10',
                    originalCanonicalValue: '10',
                    minorValue: 1000,
                ),
        );

        $this->expectException(InvalidArgumentException::class);

        new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey:
                'service-ui:commerce-sale:00000000-0000-0000-0000-000000000002',
            payments: [$payment],
        );
    }

    public function test_controller_constructs_transport_but_manager_remains_transport_opaque(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $request = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );

        $this->assertIsString($controller);
        $this->assertIsString($manager);
        $this->assertIsString($request);

        $this->assertStringContainsString(
            'settlementComponentEvidence:',
            $controller,
        );
        $this->assertStringContainsString(
            'receivableSettlementComponentEvidence:',
            $controller,
        );
        $this->assertStringContainsString(
            'paymentSettlementComponentEvidence(',
            $controller,
        );
        $this->assertStringContainsString(
            'receivableSettlementComponentEvidence(',
            $controller,
        );
        $this->assertStringContainsString(
            'paymentAmountAuthoritativeInput(',
            $request,
        );
        $this->assertStringContainsString(
            'receivableAmountAuthoritativeInput()',
            $request,
        );

        $this->assertStringNotContainsString(
            'settlementComponentEvidence',
            $manager,
        );
        $this->assertStringNotContainsString(
            'receivableSettlementComponentEvidence',
            $manager,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementMoneyAnalysisProjection',
            $manager,
        );
        $this->assertStringNotContainsString(
            'NumericalDiscrepancyAnalyzer',
            $manager,
        );
    }
}