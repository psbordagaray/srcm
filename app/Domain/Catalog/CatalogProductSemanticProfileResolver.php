<?php

namespace App\Domain\Catalog;

use App\Models\CatalogProduct;
use App\Models\ProductDefinition;
use DomainException;

class CatalogProductSemanticProfileResolver
{
    public function __construct(
        private readonly EffectiveSemanticProfileResolver $profileResolver
    ) {
    }

    public function current(
        CatalogProduct $product
    ): CatalogProductSemanticResolution {
        $value = $product->getAttribute(
            'product_definition_id'
        );

        if ($value === null) {
            return CatalogProductSemanticResolution::unclassified(
                $product
            );
        }

        $definitionId = (int) $value;

        if ($definitionId <= 0) {
            throw new DomainException(
                'La clasificación semántica almacenada del producto '
                .'es inválida.'
            );
        }

        $definition = ProductDefinition::query()
            ->whereKey($definitionId)
            ->first();

        if (! $definition) {
            throw new DomainException(
                'La definición semántica asignada al producto no existe.'
            );
        }

        $profile = $this->profileResolver
            ->currentPublished($definition);

        return CatalogProductSemanticResolution::classified(
            $product,
            $profile
        );
    }
}
