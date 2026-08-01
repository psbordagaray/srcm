<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryNegativeRegularization extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_negative_incident_id',
        'inventory_negative_incident_line_id',
        'regularizing_movement_id',
        'applied_by_user_id',
        'quantity',
        'applied_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'Una imputación de regularización es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una imputación de regularización no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(InventoryNegativeIncident::class);
    }

    public function incidentLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeIncidentLine::class,
            'inventory_negative_incident_line_id'
        );
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'regularizing_movement_id'
        );
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
