<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancySignal;
use InvalidArgumentException;

final readonly class CommerceSettlementComponentAnalysis
{
    public const SCHEMA =
        'straleon.commerce-settlement-component-analysis.v1';

    public const STATUS_ANALYZED = 'ANALYZED';

    public const STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL =
        'NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL';

    /** @var list<string> */
    public const STATUS_VALUES = [
        self::STATUS_ANALYZED,
        self::STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL,
    ];

    public const RUNTIME_WIRING_STATUS =
        'MANAGER_ANALYSIS_WIRED_HARD_FAIL_PRESERVED';

    /**
     * @param list<array{
     *     projection: CommerceSettlementMoneyAnalysisProjection,
     *     signals: list<NumericalDiscrepancySignal>
     * }> $projectionAnalyses
     */
    public function __construct(
        public string $status,
        public CommerceSettlementComponentEvidence $sourceEvidence,
        public int $systemTotalMinor,
        public int $settledTotalMinor,
        public int $otherObservedMinor,
        public ?int $conditionalResidualMinor,
        public ?string $conditionalResidualCanonical,
        public ?CommerceSettlementComponentEvidence $analysisEvidence,
        public array $projectionAnalyses,
    ) {
        if (! in_array($this->status, self::STATUS_VALUES, true)) {
            throw new InvalidArgumentException(
                'Commerce settlement component analysis status is not supported.'
            );
        }

        if (
            $this->systemTotalMinor <= 0
            || $this->settledTotalMinor <= 0
            || $this->systemTotalMinor === $this->settledTotalMinor
            || $this->otherObservedMinor < 0
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement component analysis requires a positive mismatched settlement context.'
            );
        }

        if ($this->status === self::STATUS_ANALYZED) {
            if (
                $this->conditionalResidualMinor === null
                || $this->conditionalResidualMinor <= 0
                || $this->conditionalResidualCanonical === null
                || $this->analysisEvidence === null
                || ! $this->analysisEvidence
                    ->hasConditionalResidualCandidate()
                || $this->analysisEvidence->componentId
                    !== $this->sourceEvidence->componentId
                || $this->analysisEvidence->componentType
                    !== $this->sourceEvidence->componentType
                || $this->analysisEvidence->rawHumanInput
                    !== $this->sourceEvidence->rawHumanInput
                || $this->analysisEvidence->originalCanonicalValue
                    !== $this->sourceEvidence->originalCanonicalValue
                || $this->analysisEvidence->minorValue
                    !== $this->sourceEvidence->minorValue
                || $this->analysisEvidence
                    ->conditionalResidualReferenceValue
                    !== $this->conditionalResidualCanonical
            ) {
                throw new InvalidArgumentException(
                    'Analyzed commerce settlement component result is internally inconsistent.'
                );
            }

            $this->assertProjectionAnalyses();

            return;
        }

        if (
            $this->conditionalResidualMinor !== null
            || $this->conditionalResidualCanonical !== null
            || $this->analysisEvidence !== null
            || $this->projectionAnalyses !== []
        ) {
            throw new InvalidArgumentException(
                'Non-analyzable commerce settlement component result must not invent analysis evidence.'
            );
        }
    }

    /** @return list<NumericalDiscrepancySignal> */
    public function signals(): array
    {
        $signals = [];

        foreach ($this->projectionAnalyses as $analysis) {
            foreach ($analysis['signals'] as $signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => $this->status,
            'source_component_evidence' =>
                $this->sourceEvidence->toArray(),
            'system_total_minor' => $this->systemTotalMinor,
            'settled_total_minor' => $this->settledTotalMinor,
            'other_observed_minor' => $this->otherObservedMinor,
            'conditional_residual_minor' =>
                $this->conditionalResidualMinor,
            'conditional_residual_canonical' =>
                $this->conditionalResidualCanonical,
            'analysis_component_evidence' =>
                $this->analysisEvidence?->toArray(),
            'projection_analyses' => array_map(
                static fn (array $analysis): array => [
                    'projection' =>
                        $analysis['projection']->toArray(),
                    'signals' => array_map(
                        static fn (
                            NumericalDiscrepancySignal $signal
                        ): array => $signal->toArray(),
                        $analysis['signals'],
                    ),
                ],
                $this->projectionAnalyses,
            ),
            'signals' => array_map(
                static fn (
                    NumericalDiscrepancySignal $signal
                ): array => $signal->toArray(),
                $this->signals(),
            ),
            'aggregate_discrepancy_unresolved' => true,
            'signal_priority_or_winner' => null,
            'structural_match_proves_cause' => false,
            'authorizes_correction' => false,
            'authorizes_accept_observed' => false,
            'runtime_wiring' => false,
        ];
    }

    private function assertProjectionAnalyses(): void
    {
        if ($this->projectionAnalyses === []) {
            throw new InvalidArgumentException(
                'Analyzed commerce settlement component result requires at least one canonical projection.'
            );
        }

        $projectionTypes = [];

        foreach ($this->projectionAnalyses as $analysis) {
            if (
                ! isset($analysis['projection'], $analysis['signals'])
                || ! $analysis['projection']
                    instanceof CommerceSettlementMoneyAnalysisProjection
                || ! is_array($analysis['signals'])
            ) {
                throw new InvalidArgumentException(
                    'Commerce settlement projection analysis shape is invalid.'
                );
            }

            $projection = $analysis['projection'];

            if (
                $projection->componentId
                    !== $this->sourceEvidence->componentId
                || $projection->componentType
                    !== $this->sourceEvidence->componentType
                || array_key_exists(
                    $projection->projectionType,
                    $projectionTypes,
                )
            ) {
                throw new InvalidArgumentException(
                    'Commerce settlement projection analysis identity is inconsistent.'
                );
            }

            $projectionTypes[$projection->projectionType] = true;
            $signalRuleIds = [];

            foreach ($analysis['signals'] as $signal) {
                if (! $signal instanceof NumericalDiscrepancySignal) {
                    throw new InvalidArgumentException(
                        'Commerce settlement component analysis signals must use the deterministic signal contract.'
                    );
                }

                if (
                    ! in_array(
                        $signal->ruleId,
                        $projection->targetClassifierIds,
                        true,
                    )
                    || $signal->referenceValue
                        !== $projection->referenceAnalysisValue
                    || $signal->observedValue
                        !== $projection->observedAnalysisValue
                    || array_key_exists(
                        $signal->ruleId,
                        $signalRuleIds,
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Commerce settlement component analysis signal does not match its canonical projection.'
                    );
                }

                $signalRuleIds[$signal->ruleId] = true;
            }
        }
    }
}