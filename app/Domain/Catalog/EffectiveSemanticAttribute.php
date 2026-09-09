<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use InvalidArgumentException;

final readonly class EffectiveSemanticAttribute
{
    public function __construct(
        public int $attributeBindingId,
        public int $attributeDefinitionId,
        public string $attributeDefinitionKey,
        public AttributeValueType $valueType,
        public AttributeValueScope $valueScope,
        public ?int $measurementUnitId,
        public ?string $measurementUnitKey,
        public ?int $measurementDimensionId,
        public ?string $measurementDimensionKey,
        public EffectiveSemanticAttributeProvenance $provenance,
    ) {
        if (
            $this->attributeBindingId <= 0
            || $this->attributeDefinitionId <= 0
            || $this->attributeDefinitionKey === ''
        ) {
            throw new InvalidArgumentException(
                'Effective semantic attribute identity is invalid.'
            );
        }

        $isMeasurement =
            $this->valueType === AttributeValueType::Measurement;

        $measurementAbsent =
            $this->measurementUnitId === null
            && $this->measurementUnitKey === null
            && $this->measurementDimensionId === null
            && $this->measurementDimensionKey === null;

        $measurementComplete =
            $this->measurementUnitId !== null
            && $this->measurementUnitId > 0
            && $this->measurementUnitKey !== null
            && $this->measurementUnitKey !== ''
            && $this->measurementDimensionId !== null
            && $this->measurementDimensionId > 0
            && $this->measurementDimensionKey !== null
            && $this->measurementDimensionKey !== '';

        if (
            ($isMeasurement && ! $measurementComplete)
            || (! $isMeasurement && ! $measurementAbsent)
        ) {
            throw new InvalidArgumentException(
                'Effective semantic attribute measurement topology is inconsistent.'
            );
        }

        if (
            $this->provenance->attributeBindingId
                !== $this->attributeBindingId
            || $this->provenance->attributeDefinitionId
                !== $this->attributeDefinitionId
            || $this->provenance->attributeDefinitionKey
                !== $this->attributeDefinitionKey
            || $this->provenance->measurementUnitId
                !== $this->measurementUnitId
            || $this->provenance->measurementUnitKey
                !== $this->measurementUnitKey
            || $this->provenance->measurementDimensionId
                !== $this->measurementDimensionId
            || $this->provenance->measurementDimensionKey
                !== $this->measurementDimensionKey
        ) {
            throw new InvalidArgumentException(
                'Effective semantic attribute provenance is inconsistent.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attribute_binding_id' => $this->attributeBindingId,
            'attribute_definition_id' => $this->attributeDefinitionId,
            'attribute_definition_key' => $this->attributeDefinitionKey,
            'value_type' => $this->valueType->value,
            'value_scope' => $this->valueScope->value,
            'measurement_unit_id' => $this->measurementUnitId,
            'measurement_unit_key' => $this->measurementUnitKey,
            'measurement_dimension_id' => $this->measurementDimensionId,
            'measurement_dimension_key' =>
                $this->measurementDimensionKey,
            'provenance' => $this->provenance->toArray(),
        ];
    }
}
