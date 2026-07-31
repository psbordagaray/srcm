<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CatalogProduct;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierOfferManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function create(array $data): SupplierOffer
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $data,
            $organizationId
        ): SupplierOffer {
            $supplier = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($data['supplier_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = CatalogProduct::query()
                ->whereKey($data['catalog_product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertReferencesActive($supplier, $product);
            $this->assertIdentityAvailable(
                $organizationId,
                $data
            );

            return SupplierOffer::query()
                ->create([
                    ...$data,
                    'organization_id' => $organizationId,
                ])
                ->fresh(['supplier.party', 'product']);
        });
    }

    public function update(
        SupplierOffer $offer,
        array $data
    ): SupplierOffer {
        $organizationId = $this->currentOrganization->id();

        $this->assertOfferBelongsToOrganization(
            $offer,
            $organizationId
        );

        return DB::transaction(function () use (
            $offer,
            $data,
            $organizationId
        ): SupplierOffer {
            $locked = SupplierOffer::query()
                ->forOrganization($organizationId)
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $supplier = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($data['supplier_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $product = CatalogProduct::query()
                ->whereKey($data['catalog_product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertReferencesActive($supplier, $product);

            $this->assertIdentityAvailable(
                $organizationId,
                $data,
                $locked
            );

            $locked->update($data);

            return $locked->fresh([
                'supplier.party',
                'product',
            ]);
        });
    }

    public function toggleActive(
        SupplierOffer $offer
    ): SupplierOffer {
        $organizationId = $this->currentOrganization->id();

        $this->assertOfferBelongsToOrganization(
            $offer,
            $organizationId
        );

        return DB::transaction(function () use (
            $offer,
            $organizationId
        ): SupplierOffer {
            $locked = SupplierOffer::query()
                ->forOrganization($organizationId)
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->active) {
                $supplier = Supplier::query()
                    ->forOrganization($organizationId)
                    ->whereKey($locked->supplier_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $product = CatalogProduct::query()
                    ->whereKey($locked->catalog_product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertReferencesActive(
                    $supplier,
                    $product
                );
            }

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $locked->fresh([
                'supplier.party',
                'product',
            ]);
        });
    }

    private function assertIdentityAvailable(
        int $organizationId,
        array $data,
        ?SupplierOffer $current = null
    ): void {
        $normalized = SupplierOffer::normalizeCode(
            (string) ($data['supplier_code'] ?? '')
        );

        $query = SupplierOffer::query()
            ->forOrganization($organizationId)
            ->when(
                $current,
                fn ($q) => $q->whereKeyNot(
                    $current->getKey()
                )
            )
            ->where('supplier_id', $data['supplier_id']);

        if (
            (clone $query)
                ->where(
                    'catalog_product_id',
                    $data['catalog_product_id']
                )
                ->where(
                    'normalized_supplier_code',
                    $normalized
                )
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                $normalized === ''
                    ? 'Este proveedor ya posee una oferta sin código para el producto.'
                    : 'Este proveedor ya posee la misma oferta para el producto.'
            );
        }

        if (
            $normalized !== ''
            && (clone $query)
                ->where(
                    'normalized_supplier_code',
                    $normalized
                )
                ->where(
                    'catalog_product_id',
                    '!=',
                    $data['catalog_product_id']
                )
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'El código del proveedor ya identifica otro producto dentro de la organización activa.'
            );
        }
    }

    private function assertReferencesActive(
        Supplier $supplier,
        CatalogProduct $product
    ): void {
        if (! $supplier->active) {
            throw new DomainException(
                'El proveedor está inactivo.'
            );
        }

        if (! $product->active) {
            throw new DomainException(
                'El producto maestro está inactivo.'
            );
        }
    }

    private function assertOfferBelongsToOrganization(
        SupplierOffer $offer,
        int $organizationId
    ): void {
        if ((int) $offer->organization_id !== $organizationId) {
            throw new DomainException(
                'La oferta no pertenece a la organización activa.'
            );
        }
    }
}
