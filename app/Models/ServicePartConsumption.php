<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePartConsumption extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_part_requirement_id',
        'service_part_purchase_line_id',
        'inventory_movement_line_id',
        'quantity',
        'consumed_by_user_id',
        'consumed_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El consumo del repuesto es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'El consumo del repuesto no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(
            ServicePartRequirement::class,
            'service_part_requirement_id'
        );
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(
            ServicePartPurchaseLine::class,
            'service_part_purchase_line_id'
        );
    }

    public function inventoryMovementLine(): BelongsTo
    {
        return $this->belongsTo(InventoryMovementLine::class);
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by_user_id');
    }
}
