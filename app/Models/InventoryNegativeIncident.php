<?php

namespace App\Models;

use App\Enums\InventoryNegativeIncidentStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryNegativeIncident extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['status' => 'open'];

    protected $fillable = [
        'organization_id',
        'public_id',
        'inventory_movement_id',
        'inventory_negative_request_id',
        'inventory_negative_override_id',
        'requested_by_user_id',
        'granted_by_user_id',
        'status',
        'reason',
        'opened_at',
        'regularized_at',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            if (blank($incident->public_id)) {
                $incident->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (self $incident): void {
            if (! $incident->exists) {
                if (
                    $incident->status
                        !== InventoryNegativeIncidentStatus::Open
                ) {
                    throw new DomainException(
                        'Una incidencia nueva debe quedar abierta.'
                    );
                }

                return;
            }

            if ($incident->isDirty([
                'organization_id',
                'public_id',
                'inventory_movement_id',
                'inventory_negative_request_id',
                'inventory_negative_override_id',
                'requested_by_user_id',
                'granted_by_user_id',
                'reason',
                'opened_at',
            ])) {
                throw new DomainException(
                    'El origen de una incidencia negativa es inmutable.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una incidencia de stock negativo no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InventoryNegativeIncidentStatus::class,
            'opened_at' => 'immutable_datetime',
            'regularized_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeRequest::class,
            'inventory_negative_request_id'
        );
    }

    public function override(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeOverride::class,
            'inventory_negative_override_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryNegativeIncidentLine::class)
            ->orderBy('sequence');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(
            InventoryNegativeIncidentStatusHistory::class
        )->orderBy('changed_at');
    }
}
