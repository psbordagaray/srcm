<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'inventory_location_id',
        'condition',
        'quantity',
        'base_unit_code',
        'version',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryBalance $balance): void {
            if (
                $balance->exists
                && $balance->isDirty([
                    'organization_id',
                    'catalog_product_id',
                    'inventory_location_id',
                    'condition',
                    'base_unit_code',
                ])
            ) {
                throw new DomainException(
                    'Las dimensiones de un saldo son inmutables.'
                );
            }

            $locationMatches = InventoryLocation::query()
                ->whereKey($balance->inventory_location_id)
                ->where('organization_id', $balance->organization_id)
                ->exists();

            if (! $locationMatches) {
                throw new DomainException(
                    'La ubicación del saldo no pertenece a la organización.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($balance->catalog_product_id)
                ->first();

            if (
                ! $product
                || $product->base_unit_code !== $balance->base_unit_code
            ) {
                throw new DomainException(
                    'La unidad base del saldo no coincide con la del producto.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un saldo proyectado no se elimina mediante una operación común.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'quantity' => 'decimal:6',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'inventory_location_id'
        );
    }
}
