<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\ExactDecimal;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use InvalidArgumentException;

final readonly class CommerceSettlementComponentEvidence
{
    public const SCHEMA = 'straleon.commerce-settlement-component-evidence.v1';

    public const TYPE_PAYMENT_AMOUNT = 'PAYMENT_AMOUNT';

    public const TYPE_RECEIVABLE_AMOUNT = 'RECEIVABLE_AMOUNT';

    /** @var list<string> */
    public const COMPONENT_TYPES = [
        self::TYPE_PAYMENT_AMOUNT,
        self::TYPE_RECEIVABLE_AMOUNT,
    ];

    public const PAYMENT_COMPONENT_ID_PATTERN =
        '/^payments\.(?:0|[1-9][0-9]*)\.amount$/D';

    public const RECEIVABLE_COMPONENT_ID = 'receivable_amount';

    public const CONDITIONAL_RESIDUAL_FORMULA =
        'SYSTEM_TOTAL_MINUS_SUM_OF_ALL_OTHER_OBSERVED_SETTLEMENT_COMPONENTS';

    public const CONDITIONAL_RESIDUAL_ASSUMPTION =
        'ALL_OTHER_SETTLEMENT_COMPONENTS_ARE_CORRECT';

    public const RUNTIME_WIRING_STATUS = 'FOUNDATION_ONLY_NOT_YET_WIRED';

    private function __construct(
        public string $componentId,
        public string $componentType,
        public string $rawHumanInput,
        public string $originalCanonicalValue,
        public int $minorValue,
        public ?string $conditionalResidualReferenceValue,
    ) {
        if (
            ! in_array(
                $this->componentType,
                self::COMPONENT_TYPES,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement component type is not supported.'
            );
        }

        $this->assertComponentIdentity();
        $this->assertRawHumanInput();

        $canonical = self::positiveMoney(
            $this->originalCanonicalValue,
            'Original canonical settlement component value',
        );

        if (
            ExactDecimalLegacyAdapter::toMinorUnit(
                $canonical,
                2,
            ) !== $this->minorValue
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement component canonical and minor evidence must agree exactly.'
            );
        }

        if ($this->conditionalResidualReferenceValue !== null) {
            $residual = self::positiveMoney(
                $this->conditionalResidualReferenceValue,
                'Conditional residual reference value',
            );

            if ($residual->value === $canonical->value) {
                throw new InvalidArgumentException(
                    'Conditional residual review candidate must differ from the observed canonical component value.'
                );
            }
        }
    }

    public static function payment(
        int $index,
        string $rawHumanInput,
        string $originalCanonicalValue,
        int $minorValue,
        ?string $conditionalResidualReferenceValue = null,
    ): self {
        if ($index < 0) {
            throw new InvalidArgumentException(
                'Commerce payment component index must be non-negative.'
            );
        }

        return new self(
            componentId: 'payments.'.$index.'.amount',
            componentType: self::TYPE_PAYMENT_AMOUNT,
            rawHumanInput: $rawHumanInput,
            originalCanonicalValue: $originalCanonicalValue,
            minorValue: $minorValue,
            conditionalResidualReferenceValue:
                $conditionalResidualReferenceValue,
        );
    }

    public static function receivable(
        string $rawHumanInput,
        string $originalCanonicalValue,
        int $minorValue,
        ?string $conditionalResidualReferenceValue = null,
    ): self {
        return new self(
            componentId: self::RECEIVABLE_COMPONENT_ID,
            componentType: self::TYPE_RECEIVABLE_AMOUNT,
            rawHumanInput: $rawHumanInput,
            originalCanonicalValue: $originalCanonicalValue,
            minorValue: $minorValue,
            conditionalResidualReferenceValue:
                $conditionalResidualReferenceValue,
        );
    }

    public function hasConditionalResidualCandidate(): bool
    {
        return $this->conditionalResidualReferenceValue !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'component_id' => $this->componentId,
            'component_type' => $this->componentType,
            'raw_human_input' => $this->rawHumanInput,
            'original_canonical_value' => $this->originalCanonicalValue,
            'minor_value' => $this->minorValue,
            'conditional_residual_reference_value' =>
                $this->conditionalResidualReferenceValue,
            'conditional_residual_formula' =>
                self::CONDITIONAL_RESIDUAL_FORMULA,
            'conditional_residual_is_independent_fact' => false,
            'conditional_residual_assumption' =>
                self::CONDITIONAL_RESIDUAL_ASSUMPTION,
            'review_candidate' =>
                $this->hasConditionalResidualCandidate(),
            'cause_proven' => false,
            'automatic_field_correction' => false,
            'runtime_wiring' => false,
        ];
    }

    private function assertComponentIdentity(): void
    {
        if ($this->componentType === self::TYPE_PAYMENT_AMOUNT) {
            if (
                preg_match(
                    self::PAYMENT_COMPONENT_ID_PATTERN,
                    $this->componentId,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Payment settlement component id is not canonical.'
                );
            }

            return;
        }

        if ($this->componentId !== self::RECEIVABLE_COMPONENT_ID) {
            throw new InvalidArgumentException(
                'Receivable settlement component id is not canonical.'
            );
        }
    }

    private function assertRawHumanInput(): void
    {
        if (
            $this->rawHumanInput === ''
            || strlen($this->rawHumanInput) > 18
            || trim($this->rawHumanInput) !== $this->rawHumanInput
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $this->rawHumanInput,
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement raw human input must be non-empty, bounded, trimmed and control-free.'
            );
        }
    }

    private static function positiveMoney(
        string $value,
        string $label,
    ): ExactDecimal {
        $decimal = ExactDecimal::fromCanonical($value)
            ->assertMaxScale(2);

        if ($decimal->isNegative() || $decimal->isZero()) {
            throw new InvalidArgumentException(
                $label.' must be positive.'
            );
        }

        return $decimal;
    }
}