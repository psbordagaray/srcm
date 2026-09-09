<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use InvalidArgumentException;

final readonly class EffectiveCatalogProductSemanticValue
{
    public function __construct(
        public int $catalogProductId,
        public int $productSchemaVersionId,
        public int $attributeBindingId,
        public int $attributeDefinitionId,
        public string $attributeDefinitionKey,
        public AttributeValueType $valueType,
        public AttributeValueScope $valueScope,
        public bool|int|string $value,
        public ?int $measurementUnitId,
        public ?string $measurementUnitKey,
        public ?int $measurementDimensionId,
        public ?string $measurementDimensionKey,
    ) {
        if (
            $this->catalogProductId <= 0
            || $this->productSchemaVersionId <= 0
            || $this->attributeBindingId <= 0
            || $this->attributeDefinitionId <= 0
            || trim($this->attributeDefinitionKey) === ''
            || $this->valueScope
                !== AttributeValueScope::Product
        ) {
            throw new InvalidArgumentException(
                'Effective product semantic value identity is invalid.'
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
            && filled($this->measurementUnitKey)
            && $this->measurementDimensionId !== null
            && $this->measurementDimensionId > 0
            && filled($this->measurementDimensionKey);

        if (
            ($isMeasurement && ! $measurementComplete)
            || (! $isMeasurement && ! $measurementAbsent)
        ) {
            throw new InvalidArgumentException(
                'Effective product semantic value measurement topology is inconsistent.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'catalog_product_id' => $this->catalogProductId,
            'product_schema_version_id' =>
                $this->productSchemaVersionId,
            'attribute_binding_id' => $this->attributeBindingId,
            'attribute_definition_id' =>
                $this->attributeDefinitionId,
            'attribute_definition_key' =>
                $this->attributeDefinitionKey,
            'value_type' => $this->valueType->value,
            'value_scope' => $this->valueScope->value,
            'value' => $this->value,
            'measurement_unit_id' => $this->measurementUnitId,
            'measurement_unit_key' => $this->measurementUnitKey,
            'measurement_dimension_id' =>
                $this->measurementDimensionId,
            'measurement_dimension_key' =>
                $this->measurementDimensionKey,
        ];
    }
}
