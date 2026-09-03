<?php

namespace App\Domain\Commerce;

use DomainException;
use InvalidArgumentException;

final class CommerceSettlementDiscrepancyException extends DomainException
{
    public const SCHEMA =
        'straleon.commerce-settlement-discrepancy-runtime-evidence.v1';

    public const MESSAGE =
        'Los pagos y el saldo pendiente deben cubrir exactamente el total de la venta.';

    public const RUNTIME_WIRING_STATUS =
        'MANAGER_ANALYSIS_WIRED_HARD_FAIL_PRESERVED';

    /**
     * @param list<string> $observedComponentIds
     * @param list<CommerceSettlementComponentAnalysis> $componentAnalyses
     * @param list<string> $missingTransportEvidenceComponentIds
     */
    public function __construct(
        public readonly int $systemTotalMinor,
        public readonly int $settledTotalMinor,
        public readonly array $observedComponentIds,
        public readonly array $componentAnalyses,
        public readonly array $missingTransportEvidenceComponentIds,
    ) {
        parent::__construct(self::MESSAGE);

        if (
            $this->systemTotalMinor <= 0
            || $this->settledTotalMinor <= 0
            || $this->systemTotalMinor === $this->settledTotalMinor
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement discrepancy evidence requires positive mismatched aggregate totals.'
            );
        }

        $this->assertCoverage();
    }

    public static function fromCheckoutData(
        CommerceCheckoutData $data,
        int $systemTotalMinor,
        int $settledTotalMinor,
        CommerceSettlementComponentAnalyzer $analyzer,
    ): self {
        if (
            $systemTotalMinor <= 0
            || $settledTotalMinor <= 0
            || $systemTotalMinor === $settledTotalMinor
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement discrepancy runtime analysis requires positive mismatched aggregate totals.'
            );
        }

        $observedComponentIds = [];
        $componentAnalyses = [];
        $missingTransportEvidenceComponentIds = [];

        foreach ($data->payments as $index => $payment) {
            if (! $payment instanceof CommercePaymentData) {
                throw new InvalidArgumentException(
                    'Commerce settlement discrepancy runtime analysis requires canonical payment data.'
                );
            }

            $componentId = 'payments.'.$index.'.amount';
            $observedComponentIds[] = $componentId;
            $evidence = $payment->settlementComponentEvidence;

            if ($evidence === null) {
                $missingTransportEvidenceComponentIds[] =
                    $componentId;

                continue;
            }

            if ($evidence->componentId !== $componentId) {
                throw new InvalidArgumentException(
                    'Commerce settlement discrepancy payment evidence id does not match original payment position.'
                );
            }

            $componentAnalyses[] = $analyzer->analyze(
                evidence: $evidence,
                systemTotalMinor: $systemTotalMinor,
                settledTotalMinor: $settledTotalMinor,
            );
        }

        if ($data->receivableAmountMinor !== null) {
            $componentId =
                CommerceSettlementComponentEvidence::
                    RECEIVABLE_COMPONENT_ID;
            $observedComponentIds[] = $componentId;
            $evidence =
                $data->receivableSettlementComponentEvidence;

            if ($evidence === null) {
                $missingTransportEvidenceComponentIds[] =
                    $componentId;
            } else {
                if ($evidence->componentId !== $componentId) {
                    throw new InvalidArgumentException(
                        'Commerce settlement discrepancy receivable evidence id is not canonical.'
                    );
                }

                $componentAnalyses[] = $analyzer->analyze(
                    evidence: $evidence,
                    systemTotalMinor: $systemTotalMinor,
                    settledTotalMinor: $settledTotalMinor,
                );
            }
        }

        return new self(
            systemTotalMinor: $systemTotalMinor,
            settledTotalMinor: $settledTotalMinor,
            observedComponentIds: $observedComponentIds,
            componentAnalyses: $componentAnalyses,
            missingTransportEvidenceComponentIds:
                $missingTransportEvidenceComponentIds,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'message' => self::MESSAGE,
            'system_total_minor' => $this->systemTotalMinor,
            'settled_total_minor' => $this->settledTotalMinor,
            'observed_component_ids' => $this->observedComponentIds,
            'component_analyses' => array_map(
                static fn (
                    CommerceSettlementComponentAnalysis $analysis
                ): array => $analysis->toArray(),
                $this->componentAnalyses,
            ),
            'missing_transport_evidence_component_ids' =>
                $this->missingTransportEvidenceComponentIds,
            'aggregate_discrepancy_unresolved' => true,
            'signal_priority_or_winner' => null,
            'authorizes_correction' => false,
            'authorizes_keep_reference_decision' => false,
            'authorizes_accept_observed' => false,
            'authorizes_override' => false,
            'persists_audit' => false,
            'controller_special_handling' => false,
        ];
    }

    private function assertCoverage(): void
    {
        if ($this->observedComponentIds === []) {
            throw new InvalidArgumentException(
                'Commerce settlement discrepancy evidence requires at least one observed settlement component.'
            );
        }

        $this->assertUniqueCanonicalIds(
            $this->observedComponentIds,
            'observed component',
        );
        $this->assertUniqueCanonicalIds(
            $this->missingTransportEvidenceComponentIds,
            'missing transport evidence component',
        );

        $analyzedIds = [];

        foreach ($this->componentAnalyses as $analysis) {
            if (! $analysis instanceof CommerceSettlementComponentAnalysis) {
                throw new InvalidArgumentException(
                    'Commerce settlement discrepancy component analyses must use the canonical analysis contract.'
                );
            }

            $analyzedIds[] =
                $analysis->sourceEvidence->componentId;
        }

        $this->assertUniqueCanonicalIds(
            $analyzedIds,
            'analyzed component',
        );

        foreach ($this->observedComponentIds as $componentId) {
            $isAnalyzed = in_array(
                $componentId,
                $analyzedIds,
                true,
            );
            $isMissing = in_array(
                $componentId,
                $this->missingTransportEvidenceComponentIds,
                true,
            );

            if ($isAnalyzed === $isMissing) {
                throw new InvalidArgumentException(
                    'Every observed settlement component must be covered exactly once by analysis or missing-evidence status.'
                );
            }
        }

        foreach (
            array_merge(
                $analyzedIds,
                $this->missingTransportEvidenceComponentIds,
            )
            as $componentId
        ) {
            if (
                ! in_array(
                    $componentId,
                    $this->observedComponentIds,
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Commerce settlement discrepancy coverage contains an unobserved component.'
                );
            }
        }
    }

    /**
     * @param list<string> $componentIds
     */
    private function assertUniqueCanonicalIds(
        array $componentIds,
        string $label,
    ): void {
        if (count(array_unique($componentIds)) !== count($componentIds)) {
            throw new InvalidArgumentException(
                'Commerce settlement discrepancy '.$label.' ids must be unique.'
            );
        }

        foreach ($componentIds as $componentId) {
            if (
                $componentId
                    !== CommerceSettlementComponentEvidence::
                        RECEIVABLE_COMPONENT_ID
                && preg_match(
                    CommerceSettlementComponentEvidence::
                        PAYMENT_COMPONENT_ID_PATTERN,
                    $componentId,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Commerce settlement discrepancy '.$label.' id is not canonical.'
                );
            }
        }
    }
}