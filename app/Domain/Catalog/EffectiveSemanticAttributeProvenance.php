<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class EffectiveSemanticAttributeProvenance
{
    public const RULE_EXACT_SCHEMA_BINDING =
        'declared_by_exact_schema_binding';

    public function __construct(
        public int $productSchemaVersionId,
        public int $attributeBindingId,
        public int $attributeDefinitionId,
        public string $attributeDefinitionKey,
        public ?int $measurementUnitId,
        public ?string $measurementUnitKey,
        public ?int $measurementDimensionId,
        public ?string $measurementDimensionKey,
        public string $resolutionRule = self::RULE_EXACT_SCHEMA_BINDING,
    ) {
        if (
            $this->productSchemaVersionId <= 0
            || $this->attributeBindingId <= 0
            || $this->attributeDefinitionId <= 0
            || $this->attributeDefinitionKey === ''
            || $this->resolutionRule !== self::RULE_EXACT_SCHEMA_BINDING
        ) {
            throw new InvalidArgumentException(
                'Effective semantic attribute provenance identity is invalid.'
            );
        }

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

        if (! $measurementAbsent && ! $measurementComplete) {
            throw new InvalidArgumentException(
                'Measurement provenance must be completely present or completely absent.'
            );
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'product_schema_version_id' => $this->productSchemaVersionId,
            'attribute_binding_id' => $this->attributeBindingId,
            'attribute_definition_id' => $this->attributeDefinitionId,
            'attribute_definition_key' => $this->attributeDefinitionKey,
            'measurement_unit_id' => $this->measurementUnitId,
            'measurement_unit_key' => $this->measurementUnitKey,
            'measurement_dimension_id' => $this->measurementDimensionId,
            'measurement_dimension_key' => $this->measurementDimensionKey,
            'resolution_rule' => $this->resolutionRule,
        ];
    }
}
