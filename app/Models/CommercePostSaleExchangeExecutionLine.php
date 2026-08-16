<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePostSaleExchangeExecutionLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_exchange_execution_id',
        'commerce_post_sale_exchange_selection_line_id',
        'inventory_movement_line_id',
        'sequence',
        'source_location_id',
        'condition',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Una línea de ejecución de cambio es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una línea de ejecución de cambio no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'condition' => InventoryCondition::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeExecution::class,
            'commerce_post_sale_exchange_execution_id'
        );
    }

    public function selectionLine(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeSelectionLine::class,
            'commerce_post_sale_exchange_selection_line_id'
        );
    }

    public function inventoryMovementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'source_location_id'
        );
    }
}
