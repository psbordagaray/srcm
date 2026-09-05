<?php

namespace App\Domain\Inventory;

use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\ProductPresentation;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ProductPresentationManager
{
    public function create(
        int $organizationId,
        int $catalogProductId,
        string $unitCode,
        string $name,
        string $conversionFactor,
        int $quantityScale = 0
    ): ProductPresentation {
        $unitCode = ProductPresentation::normalizeUnitCode($unitCode);
        $name = ProductPresentation::normalizeName($name);
        $conversionFactor = InventoryQuantity::factor(
            $conversionFactor
        );

        return DB::transaction(function () use (
            $organizationId,
            $catalogProductId,
            $unitCode,
            $name,
            $conversionFactor,
            $quantityScale
        ): ProductPresentation {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $organization) {
                throw new DomainException(
                    'La organización de la presentación no está activa.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new DomainException(
                    'El producto de la presentación no está activo.'
                );
            }

            $existing = ProductPresentation::query()
                ->forOrganization($organizationId)
                ->where('catalog_product_id', $catalogProductId)
                ->where('unit_code', $unitCode)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->active
                    && $existing->name === $name
                    && (int) $existing->quantity_scale === $quantityScale
                    && (string) $existing->conversion_factor
                        === $conversionFactor
                    && (string) $existing->base_unit_code
                        === (string) $product->base_unit_code
                    && (int) $existing->base_quantity_scale
                        === (int) $product->quantity_scale
                ) {
                    return $existing;
                }

                throw new DomainException(
                    'La presentación ya existe con un contrato cuantitativo diferente.'
                );
            }

            return ProductPresentation::query()->create([
                'organization_id' => $organizationId,
                'catalog_product_id' => $catalogProductId,
                'unit_code' => $unitCode,
                'name' => $name,
                'quantity_scale' => $quantityScale,
                'conversion_factor' => $conversionFactor,
                'active' => true,
            ])->refresh();
        }, 3);
    }

    public function convert(
        int $organizationId,
        int $catalogProductId,
        string $unitCode,
        mixed $enteredQuantity
    ): string {
        $unitCode = ProductPresentation::normalizeUnitCode($unitCode);

        $presentation = ProductPresentation::query()
            ->forOrganization($organizationId)
            ->where('catalog_product_id', $catalogProductId)
            ->where('unit_code', $unitCode)
            ->where('active', true)
            ->first();

        if (! $presentation) {
            throw new DomainException(
                'No existe una presentación activa para esa unidad.'
            );
        }

        return $presentation->toBaseQuantity($enteredQuantity);
    }
}
