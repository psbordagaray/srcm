<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryNegativeRequestLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_negative_request_id',
        'sequence',
        'catalog_product_id',
        'inventory_location_id',
        'condition',
        'current_quantity',
        'requested_quantity',
        'incoming_quantity',
        'projected_quantity',
        'current_deficit',
        'projected_deficit',
        'incremental_deficit',
        'base_unit_code',
        'balance_version',
        'creates_negative',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->exists && $line->isDirty()) {
                throw new DomainException(
                    'Una posición solicitada es inmutable.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una posición solicitada no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'condition' => InventoryCondition::class,
            'current_quantity' => 'decimal:6',
            'requested_quantity' => 'decimal:6',
            'incoming_quantity' => 'decimal:6',
            'projected_quantity' => 'decimal:6',
            'current_deficit' => 'decimal:6',
            'projected_deficit' => 'decimal:6',
            'incremental_deficit' => 'decimal:6',
            'balance_version' => 'integer',
            'creates_negative' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeRequest::class,
            'inventory_negative_request_id'
        );
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
