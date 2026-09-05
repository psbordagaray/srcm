<?php

namespace App\Models;

use App\Enums\FractionalContainerConsumptionPolicy;
use App\Enums\FractionalContainerState;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FractionalContainerConsumption extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_movement_line_id',
        'fractional_container_id',
        'sequence',
        'policy',
        'consumed_base_quantity',
        'base_unit_code',
        'state_before',
        'state_after',
        'remaining_before',
        'remaining_after',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new DomainException(
                'El historial de consumo fraccionado sólo puede '
                .'materializarse mediante su manager transaccional.'
            );
        });

        static::updating(function (): void {
            throw new DomainException(
                'El historial de consumo fraccionado es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'El historial de consumo fraccionado no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'policy' => FractionalContainerConsumptionPolicy::class,
            'consumed_base_quantity' => 'decimal:6',
            'state_before' => FractionalContainerState::class,
            'state_after' => FractionalContainerState::class,
            'remaining_before' => 'decimal:6',
            'remaining_after' => 'decimal:6',
        ];
    }

    public function movementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(
            FractionalContainer::class,
            'fractional_container_id'
        );
    }
}
