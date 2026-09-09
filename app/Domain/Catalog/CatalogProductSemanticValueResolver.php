<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeValueScope;
use App\Models\CatalogProduct;
use App\Models\CatalogProductSemanticValue;
use DomainException;

class CatalogProductSemanticValueResolver
{
    public function __construct(
        private readonly CatalogProductSemanticProfileResolver $profileResolver
    ) {
    }

    /**
     * @return list<EffectiveCatalogProductSemanticValue>
     */
    public function current(
        CatalogProduct $product
    ): array {
        $resolution = $this->profileResolver
            ->current($product);

        if (! $resolution->classified) {
            return [];
        }

        $profile = $resolution->profile;

        if (! $profile) {
            throw new DomainException(
                'La resolución semántica clasificada no contiene perfil.'
            );
        }

        $attributes = array_values(
            array_filter(
                $profile->attributes,
                static fn (
                    EffectiveSemanticAttribute $attribute
                ): bool =>
                    $attribute->valueScope
                        === AttributeValueScope::Product
            )
        );

        if ($attributes === []) {
            return [];
        }

        $bindingIds = array_map(
            static fn (
                EffectiveSemanticAttribute $attribute
            ): int => $attribute->attributeBindingId,
            $attributes
        );

        $stored = CatalogProductSemanticValue::query()
            ->where(
                'catalog_product_id',
                $product->getKey()
            )
            ->whereIn(
                'attribute_binding_id',
                $bindingIds
            )
            ->get()
            ->keyBy('attribute_binding_id');

        $resolved = [];

        foreach ($attributes as $attribute) {
            $value = $stored->get(
                $attribute->attributeBindingId
            );

            if (! $value) {
                continue;
            }

            $value->assertStoredContract();

            $resolved[] =
                new EffectiveCatalogProductSemanticValue(
                    catalogProductId:
                        (int) $product->getKey(),
                    productSchemaVersionId:
                        $profile->productSchemaVersionId,
                    attributeBindingId:
                        $attribute->attributeBindingId,
                    attributeDefinitionId:
                        $attribute->attributeDefinitionId,
                    attributeDefinitionKey:
                        $attribute->attributeDefinitionKey,
                    valueType:
                        $attribute->valueType,
                    valueScope:
                        $attribute->valueScope,
                    value:
                        $value->canonicalValue(),
                    measurementUnitId:
                        $attribute->measurementUnitId,
                    measurementUnitKey:
                        $attribute->measurementUnitKey,
                    measurementDimensionId:
                        $attribute->measurementDimensionId,
                    measurementDimensionKey:
                        $attribute->measurementDimensionKey,
                );
        }

        return $resolved;
    }
}
