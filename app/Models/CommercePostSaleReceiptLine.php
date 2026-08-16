<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercePostSaleReceiptLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_receipt_id',
        'commerce_post_sale_request_line_id',
        'inventory_movement_line_id',
        'quantity',
        'condition',
        'destination_location_id',
        'notes',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Una línea de recepción física de posventa es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una línea de recepción física de posventa no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'condition' => InventoryCondition::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleReceipt::class,
            'commerce_post_sale_receipt_id'
        );
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleRequestLine::class,
            'commerce_post_sale_request_line_id'
        );
    }

    public function inventoryMovementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'destination_location_id'
        );
    }

    public function resolutionLines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleResolutionLine::class,
            'commerce_post_sale_receipt_line_id'
        )->orderBy('id');
    }
}
