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
        'reviewed_by_user_id',
        'reviewed_at',
        'review_reason',
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

            $original = InventoryNegativeIncidentStatus::from(
                (string) $incident->getRawOriginal('status')
            );
            $current = $incident->status;
            $allowed = match ($original) {
                InventoryNegativeIncidentStatus::Open => in_array(
                    $current,
                    [
                        InventoryNegativeIncidentStatus::Open,
                        InventoryNegativeIncidentStatus::UnderReview,
                        InventoryNegativeIncidentStatus::Resolved,
                    ],
                    true
                ),
                InventoryNegativeIncidentStatus::UnderReview => in_array(
                    $current,
                    [
                        InventoryNegativeIncidentStatus::UnderReview,
                        InventoryNegativeIncidentStatus::Resolved,
                    ],
                    true
                ),
                InventoryNegativeIncidentStatus::Resolved =>
                    $current === InventoryNegativeIncidentStatus::Resolved,
            };

            if (! $allowed) {
                throw new DomainException(
                    'La transición de estado de la incidencia es inválida.'
                );
            }

            if (
                $incident->getRawOriginal('regularized_at') !== null
                && $incident->isDirty('regularized_at')
            ) {
                throw new DomainException(
                    'La fecha de regularización es inmutable una vez fijada.'
                );
            }

            if ($current === InventoryNegativeIncidentStatus::Resolved) {
                if (
                    $incident->regularized_at === null
                    || $incident->resolved_by_user_id === null
                    || $incident->resolved_at === null
                    || blank($incident->resolution_reason)
                ) {
                    throw new DomainException(
                        'Una incidencia resuelta requiere regularización y atribución completas.'
                    );
                }

                return;
            }

            if ($current === InventoryNegativeIncidentStatus::UnderReview) {
                if (
                    $incident->reviewed_by_user_id === null
                    || $incident->reviewed_at === null
                    || blank($incident->review_reason)
                ) {
                    throw new DomainException(
                        'Una incidencia en revisión requiere atribución completa.'
                    );
                }
            } elseif (
                $incident->reviewed_by_user_id !== null
                || $incident->reviewed_at !== null
                || $incident->review_reason !== null
            ) {
                throw new DomainException(
                    'Sólo una incidencia revisada admite datos de revisión.'
                );
            }

            if (
                $incident->resolved_by_user_id !== null
                || $incident->resolved_at !== null
                || $incident->resolution_reason !== null
            ) {
                throw new DomainException(
                    'Sólo una incidencia resuelta admite datos de resolución.'
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
            'reviewed_at' => 'immutable_datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
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

    public function regularizations(): HasMany
    {
        return $this->hasMany(InventoryNegativeRegularization::class);
    }
}
