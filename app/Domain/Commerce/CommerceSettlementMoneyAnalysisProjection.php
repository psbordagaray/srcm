<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\AdjacentTranspositionClassifier;
use App\Domain\Numerics\DigitDuplicationClassifier;
use App\Domain\Numerics\DigitOmissionClassifier;
use App\Domain\Numerics\DigitSubstitutionClassifier;
use App\Domain\Numerics\ExactDecimal;
use App\Domain\Numerics\ModuloNineTranspositionSignalClassifier;
use App\Domain\Numerics\SeparatorMisplacementClassifier;
use InvalidArgumentException;

final readonly class CommerceSettlementMoneyAnalysisProjection
{
    public const SCHEMA =
        'straleon.commerce-settlement-money-analysis-projection.v1';

    public const TYPE_DIGIT_STRUCTURAL = 'DIGIT_STRUCTURAL';

    public const TYPE_SEPARATOR_MISPLACEMENT = 'SEPARATOR_MISPLACEMENT';

    /** @var list<string> */
    public const PROJECTION_TYPES = [
        self::TYPE_DIGIT_STRUCTURAL,
        self::TYPE_SEPARATOR_MISPLACEMENT,
    ];

    public const MONEY_SCALE = 2;

    public const DIGIT_STRUCTURAL_DERIVATION =
        'FIXED_SCALE_2_DECIMAL_TO_ASCII_DIGIT_SEQUENCE';

    public const SEPARATOR_DERIVATION =
        'FIXED_SCALE_2_REFERENCE_RENDERED_WITH_OBSERVED_DECIMAL_SEPARATOR_SYMBOL';

    /** @var list<string> */
    public const DIGIT_STRUCTURAL_CLASSIFIER_IDS = [
        AdjacentTranspositionClassifier::IDENTIFIER,
        DigitOmissionClassifier::IDENTIFIER,
        DigitDuplicationClassifier::IDENTIFIER,
        DigitSubstitutionClassifier::IDENTIFIER,
        ModuloNineTranspositionSignalClassifier::IDENTIFIER,
    ];

    /** @var list<string> */
    public const SEPARATOR_CLASSIFIER_IDS = [
        SeparatorMisplacementClassifier::IDENTIFIER,
    ];

    public const INPUT_SELECTION_STATUS =
        'PROJECTION_FOUNDATION_DEFINED_NOT_RUNTIME_WIRED';

    public const RUNTIME_WIRING_STATUS =
        'FOUNDATION_ONLY_NOT_YET_WIRED';

    /**
     * @param list<string> $targetClassifierIds
     */
    private function __construct(
        public string $projectionType,
        public string $componentId,
        public string $componentType,
        public string $sourceRawHumanInput,
        public string $sourceOriginalCanonicalValue,
        public int $sourceMinorValue,
        public string $sourceConditionalResidualReferenceValue,
        public string $referenceAnalysisValue,
        public string $observedAnalysisValue,
        public string $derivationRule,
        public array $targetClassifierIds,
    ) {
        if (! in_array($this->projectionType, self::PROJECTION_TYPES, true)) {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection type is not supported.'
            );
        }

        if ($this->componentId === '' || $this->componentType === '') {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection requires source component identity.'
            );
        }

        if ($this->referenceAnalysisValue === $this->observedAnalysisValue) {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection requires distinct analysis values.'
            );
        }

        $expectedClassifierIds = $this->projectionType
            === self::TYPE_DIGIT_STRUCTURAL
                ? self::DIGIT_STRUCTURAL_CLASSIFIER_IDS
                : self::SEPARATOR_CLASSIFIER_IDS;

        if ($this->targetClassifierIds !== $expectedClassifierIds) {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection classifier subset is not canonical.'
            );
        }
    }

    public static function digitStructural(
        CommerceSettlementComponentEvidence $evidence,
    ): self {
        $reference = self::requiredConditionalResidual($evidence);

        return new self(
            projectionType: self::TYPE_DIGIT_STRUCTURAL,
            componentId: $evidence->componentId,
            componentType: $evidence->componentType,
            sourceRawHumanInput: $evidence->rawHumanInput,
            sourceOriginalCanonicalValue:
                $evidence->originalCanonicalValue,
            sourceMinorValue: $evidence->minorValue,
            sourceConditionalResidualReferenceValue: $reference,
            referenceAnalysisValue: self::fixedScaleDigits($reference),
            observedAnalysisValue: self::fixedScaleDigits(
                $evidence->originalCanonicalValue,
            ),
            derivationRule: self::DIGIT_STRUCTURAL_DERIVATION,
            targetClassifierIds:
                self::DIGIT_STRUCTURAL_CLASSIFIER_IDS,
        );
    }

    public static function separatorMisplacement(
        CommerceSettlementComponentEvidence $evidence,
    ): ?self {
        $reference = self::requiredConditionalResidual($evidence);
        $raw = $evidence->rawHumanInput;

        if (preg_match('/^[0-9]+$/D', $raw) === 1) {
            return null;
        }

        if (
            preg_match(
                '/^[0-9]+([.,])[0-9]{1,2}$/D',
                $raw,
                $match,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement separator projection requires canonical positive-money raw input or an integer raw input.'
            );
        }

        $separator = $match[1];

        return new self(
            projectionType: self::TYPE_SEPARATOR_MISPLACEMENT,
            componentId: $evidence->componentId,
            componentType: $evidence->componentType,
            sourceRawHumanInput: $raw,
            sourceOriginalCanonicalValue:
                $evidence->originalCanonicalValue,
            sourceMinorValue: $evidence->minorValue,
            sourceConditionalResidualReferenceValue: $reference,
            referenceAnalysisValue:
                self::fixedScaleDecimal($reference, $separator),
            observedAnalysisValue: $raw,
            derivationRule: self::SEPARATOR_DERIVATION,
            targetClassifierIds: self::SEPARATOR_CLASSIFIER_IDS,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'projection_type' => $this->projectionType,
            'source_component_schema' =>
                CommerceSettlementComponentEvidence::SCHEMA,
            'source_component_id' => $this->componentId,
            'source_component_type' => $this->componentType,
            'source_raw_human_input' => $this->sourceRawHumanInput,
            'source_original_canonical_value' =>
                $this->sourceOriginalCanonicalValue,
            'source_minor_value' => $this->sourceMinorValue,
            'source_conditional_residual_reference_value' =>
                $this->sourceConditionalResidualReferenceValue,
            'reference_analysis_value' =>
                $this->referenceAnalysisValue,
            'observed_analysis_value' =>
                $this->observedAnalysisValue,
            'derivation_rule' => $this->derivationRule,
            'target_classifier_ids' => $this->targetClassifierIds,
            'classifier_subset_enforced' => true,
            'run_all_classifiers' => false,
            'derived_analysis_view' => true,
            'source_normalization' => false,
            'source_replacement' => false,
            'authorizes_correction' => false,
            'proves_human_cause' => false,
            'runtime_wiring' => false,
        ];
    }

    private static function requiredConditionalResidual(
        CommerceSettlementComponentEvidence $evidence,
    ): string {
        $reference =
            $evidence->conditionalResidualReferenceValue;

        if ($reference === null) {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection requires a conditional residual review candidate.'
            );
        }

        self::positiveMoney($reference);

        return $reference;
    }

    private static function fixedScaleDigits(string $value): string
    {
        [$integer, $fraction] = self::fixedScaleParts($value);

        return $integer.$fraction;
    }

    private static function fixedScaleDecimal(
        string $value,
        string $separator,
    ): string {
        if (! in_array($separator, ['.', ','], true)) {
            throw new InvalidArgumentException(
                'Commerce settlement separator projection requires dot or comma.'
            );
        }

        [$integer, $fraction] = self::fixedScaleParts($value);

        return $integer.$separator.$fraction;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function fixedScaleParts(string $value): array
    {
        $decimal = self::positiveMoney($value);
        $parts = explode('.', $decimal->value, 2);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        return [
            $integer,
            str_pad(
                $fraction,
                self::MONEY_SCALE,
                '0',
                STR_PAD_RIGHT,
            ),
        ];
    }

    private static function positiveMoney(string $value): ExactDecimal
    {
        $decimal = ExactDecimal::fromCanonical($value)
            ->assertMaxScale(self::MONEY_SCALE);

        if ($decimal->isNegative() || $decimal->isZero()) {
            throw new InvalidArgumentException(
                'Commerce settlement money analysis projection values must be positive.'
            );
        }

        return $decimal;
    }
}