<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryNegativeIncidentLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_negative_incident_id',
        'sequence',
        'catalog_product_id',
        'inventory_location_id',
        'condition',
        'previous_quantity',
        'outgoing_quantity',
        'incoming_quantity',
        'net_quantity',
        'resulting_quantity',
        'previous_deficit',
        'resulting_deficit',
        'incremental_deficit',
        'pending_deficit',
        'base_unit_code',
        'regularized_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'La línea de incidencia aún no admite regularizaciones.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una línea de incidencia no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'condition' => InventoryCondition::class,
            'previous_quantity' => 'decimal:6',
            'outgoing_quantity' => 'decimal:6',
            'incoming_quantity' => 'decimal:6',
            'net_quantity' => 'decimal:6',
            'resulting_quantity' => 'decimal:6',
            'previous_deficit' => 'decimal:6',
            'resulting_deficit' => 'decimal:6',
            'incremental_deficit' => 'decimal:6',
            'pending_deficit' => 'decimal:6',
            'regularized_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeIncident::class,
            'inventory_negative_incident_id'
        );
    }
}
