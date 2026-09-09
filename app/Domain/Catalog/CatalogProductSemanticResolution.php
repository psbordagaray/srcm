<?php

namespace App\Domain\Catalog;

use App\Models\CatalogProduct;
use InvalidArgumentException;

final readonly class CatalogProductSemanticResolution
{
    public function __construct(
        public int $catalogProductId,
        public bool $classified,
        public ?int $productDefinitionId,
        public ?string $productDefinitionKey,
        public ?EffectiveSemanticProfile $profile,
    ) {
        if ($this->catalogProductId <= 0) {
            throw new InvalidArgumentException(
                'Catalog product semantic resolution identity is invalid.'
            );
        }

        if (! $this->classified) {
            if (
                $this->productDefinitionId !== null
                || $this->productDefinitionKey !== null
                || $this->profile !== null
            ) {
                throw new InvalidArgumentException(
                    'Unclassified semantic resolution cannot carry a profile.'
                );
            }

            return;
        }

        if (
            $this->productDefinitionId === null
            || $this->productDefinitionId <= 0
            || trim((string) $this->productDefinitionKey) === ''
            || $this->profile === null
        ) {
            throw new InvalidArgumentException(
                'Classified semantic resolution requires canonical identity.'
            );
        }

        if (
            $this->profile->productDefinitionId
                !== $this->productDefinitionId
            || $this->profile->productDefinitionKey
                !== $this->productDefinitionKey
        ) {
            throw new InvalidArgumentException(
                'Catalog product semantic resolution profile is inconsistent.'
            );
        }
    }

    public static function unclassified(
        CatalogProduct $product
    ): self {
        return new self(
            catalogProductId: (int) $product->getKey(),
            classified: false,
            productDefinitionId: null,
            productDefinitionKey: null,
            profile: null,
        );
    }

    public static function classified(
        CatalogProduct $product,
        EffectiveSemanticProfile $profile
    ): self {
        $assignedDefinitionId = (int) $product
            ->getAttribute('product_definition_id');

        if (
            $assignedDefinitionId <= 0
            || $assignedDefinitionId
                !== $profile->productDefinitionId
        ) {
            throw new InvalidArgumentException(
                'Catalog product assignment does not match semantic profile.'
            );
        }

        return new self(
            catalogProductId: (int) $product->getKey(),
            classified: true,
            productDefinitionId:
                $profile->productDefinitionId,
            productDefinitionKey:
                $profile->productDefinitionKey,
            profile: $profile,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'catalog_product_id' => $this->catalogProductId,
            'classification_status' =>
                $this->classified
                    ? 'classified'
                    : 'unclassified',
            'product_definition_id' =>
                $this->productDefinitionId,
            'product_definition_key' =>
                $this->productDefinitionKey,
            'profile' =>
                $this->profile?->toArray(),
        ];
    }
}
