<?php

namespace App\Models;

use App\Enums\InventoryNegativeIncidentStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryNegativeIncidentStatusHistory extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_negative_incident_status_histories';

    protected $fillable = [
        'organization_id',
        'inventory_negative_incident_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'changed_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'La historia de una incidencia es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La historia de una incidencia no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => InventoryNegativeIncidentStatus::class,
            'to_status' => InventoryNegativeIncidentStatus::class,
            'changed_at' => 'immutable_datetime',
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
