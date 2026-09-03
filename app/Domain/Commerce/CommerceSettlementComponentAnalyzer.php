<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\AdjacentTranspositionClassifier;
use App\Domain\Numerics\DigitDuplicationClassifier;
use App\Domain\Numerics\DigitOmissionClassifier;
use App\Domain\Numerics\DigitSubstitutionClassifier;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Domain\Numerics\ModuloNineTranspositionSignalClassifier;
use App\Domain\Numerics\NumericalDiscrepancyAnalyzer;
use App\Domain\Numerics\SeparatorMisplacementClassifier;
use InvalidArgumentException;

final class CommerceSettlementComponentAnalyzer
{
    public const FOUNDATION_VERSION = 1;

    public const ANALYSIS_TRIGGER =
        'ONLY_WHEN_TOTAL_POSITIVE_AND_SETTLED_TOTAL_DIFFERS_FROM_SYSTEM_TOTAL';

    public const MANAGER_INSERTION_BOUNDARY =
        'AFTER_SYSTEM_TOTAL_AND_SETTLED_TOTAL_DERIVED_BEFORE_CURRENT_MISMATCH_DOMAIN_EXCEPTION';

    public const CONDITIONAL_RESIDUAL_CALCULATION_DOMAIN =
        'INTEGER_MINOR_UNITS_SCALE_2';

    public const NON_POSITIVE_CANDIDATE_STATUS =
        CommerceSettlementComponentAnalysis::
            STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL;

    public const RUNTIME_WIRING_STATUS =
        CommerceSettlementComponentAnalysis::RUNTIME_WIRING_STATUS;

    public function analyze(
        CommerceSettlementComponentEvidence $evidence,
        int $systemTotalMinor,
        int $settledTotalMinor,
    ): CommerceSettlementComponentAnalysis {
        if (
            $systemTotalMinor <= 0
            || $settledTotalMinor <= 0
            || $systemTotalMinor === $settledTotalMinor
            || $settledTotalMinor < $evidence->minorValue
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement component analyzer requires a positive mismatched aggregate containing the observed component.'
            );
        }

        $otherObservedMinor =
            $settledTotalMinor - $evidence->minorValue;
        $candidateMinor =
            $systemTotalMinor - $otherObservedMinor;

        if ($candidateMinor <= 0) {
            return new CommerceSettlementComponentAnalysis(
                status:
                    CommerceSettlementComponentAnalysis::
                        STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL,
                sourceEvidence: $evidence,
                systemTotalMinor: $systemTotalMinor,
                settledTotalMinor: $settledTotalMinor,
                otherObservedMinor: $otherObservedMinor,
                conditionalResidualMinor: null,
                conditionalResidualCanonical: null,
                analysisEvidence: null,
                projectionAnalyses: [],
            );
        }

        $candidateCanonical =
            ExactDecimalLegacyAdapter::fromMinorUnit(
                $candidateMinor,
                CommerceSettlementMoneyAnalysisProjection::MONEY_SCALE,
            )->value;

        $analysisEvidence = $this->withResidualCandidate(
            $evidence,
            $candidateCanonical,
        );

        $digitProjection =
            CommerceSettlementMoneyAnalysisProjection::
                digitStructural($analysisEvidence);

        $projectionAnalyses = [[
            'projection' => $digitProjection,
            'signals' => $this->digitAnalyzer()->analyze(
                $digitProjection->referenceAnalysisValue,
                $digitProjection->observedAnalysisValue,
            ),
        ]];

        $separatorProjection =
            CommerceSettlementMoneyAnalysisProjection::
                separatorMisplacement($analysisEvidence);

        if ($separatorProjection !== null) {
            $projectionAnalyses[] = [
                'projection' => $separatorProjection,
                'signals' => $this->separatorAnalyzer()->analyze(
                    $separatorProjection->referenceAnalysisValue,
                    $separatorProjection->observedAnalysisValue,
                ),
            ];
        }

        return new CommerceSettlementComponentAnalysis(
            status:
                CommerceSettlementComponentAnalysis::STATUS_ANALYZED,
            sourceEvidence: $evidence,
            systemTotalMinor: $systemTotalMinor,
            settledTotalMinor: $settledTotalMinor,
            otherObservedMinor: $otherObservedMinor,
            conditionalResidualMinor: $candidateMinor,
            conditionalResidualCanonical: $candidateCanonical,
            analysisEvidence: $analysisEvidence,
            projectionAnalyses: $projectionAnalyses,
        );
    }

    private function withResidualCandidate(
        CommerceSettlementComponentEvidence $evidence,
        string $candidateCanonical,
    ): CommerceSettlementComponentEvidence {
        if (
            $evidence->componentType
                === CommerceSettlementComponentEvidence::
                    TYPE_RECEIVABLE_AMOUNT
        ) {
            return CommerceSettlementComponentEvidence::receivable(
                rawHumanInput: $evidence->rawHumanInput,
                originalCanonicalValue:
                    $evidence->originalCanonicalValue,
                minorValue: $evidence->minorValue,
                conditionalResidualReferenceValue:
                    $candidateCanonical,
            );
        }

        if (
            preg_match(
                CommerceSettlementComponentEvidence::
                    PAYMENT_COMPONENT_ID_PATTERN,
                $evidence->componentId,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Payment settlement component id cannot be rebuilt for analysis.'
            );
        }

        $parts = explode('.', $evidence->componentId);
        $index = filter_var(
            $parts[1] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($index === false || $index < 0) {
            throw new InvalidArgumentException(
                'Payment settlement component index cannot be rebuilt for analysis.'
            );
        }

        return CommerceSettlementComponentEvidence::payment(
            index: $index,
            rawHumanInput: $evidence->rawHumanInput,
            originalCanonicalValue:
                $evidence->originalCanonicalValue,
            minorValue: $evidence->minorValue,
            conditionalResidualReferenceValue:
                $candidateCanonical,
        );
    }

    private function digitAnalyzer(): NumericalDiscrepancyAnalyzer
    {
        return new NumericalDiscrepancyAnalyzer([
            new AdjacentTranspositionClassifier(),
            new DigitOmissionClassifier(),
            new DigitDuplicationClassifier(),
            new DigitSubstitutionClassifier(),
            new ModuloNineTranspositionSignalClassifier(),
        ]);
    }

    private function separatorAnalyzer(): NumericalDiscrepancyAnalyzer
    {
        return new NumericalDiscrepancyAnalyzer([
            new SeparatorMisplacementClassifier(),
        ]);
    }
}