<?php

namespace App\Models;

use App\Enums\InventoryNegativeOverrideStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryNegativeOverride extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'organization_id',
        'public_id',
        'inventory_negative_request_id',
        'inventory_movement_id',
        'authorized_user_id',
        'granted_by_user_id',
        'status',
        'movement_fingerprint',
        'snapshot_fingerprint',
        'issued_at',
        'consumed_at',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $override): void {
            if (blank($override->public_id)) {
                $override->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (self $override): void {
            $override->guardImmutableCore();
            $override->guardTransition();
            $override->guardStatusMetadata();
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un Override de stock negativo no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InventoryNegativeOverrideStatus::class,
            'issued_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            InventoryNegativeRequest::class,
            'inventory_negative_request_id'
        );
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function authorizedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    private function guardImmutableCore(): void
    {
        if ($this->exists && $this->isDirty([
            'organization_id',
            'public_id',
            'inventory_negative_request_id',
            'inventory_movement_id',
            'authorized_user_id',
            'granted_by_user_id',
            'movement_fingerprint',
            'snapshot_fingerprint',
            'issued_at',
        ])) {
            throw new DomainException(
                'El contenido de un Override de stock negativo es inmutable.'
            );
        }
    }

    private function guardTransition(): void
    {
        if (! $this->exists || ! $this->isDirty('status')) {
            return;
        }

        $from = InventoryNegativeOverrideStatus::tryFrom(
            (string) $this->getRawOriginal('status')
        );

        if (
            $from !== InventoryNegativeOverrideStatus::Active
            || ! in_array($this->status, [
                InventoryNegativeOverrideStatus::Consumed,
                InventoryNegativeOverrideStatus::Revoked,
                InventoryNegativeOverrideStatus::Invalidated,
            ], true)
        ) {
            throw new DomainException(
                'La transición del Override de stock negativo es inválida.'
            );
        }
    }

    private function guardStatusMetadata(): void
    {
        $valid = match ($this->status) {
            InventoryNegativeOverrideStatus::Active =>
                $this->consumed_at === null
                && $this->revoked_at === null
                && $this->revoked_by_user_id === null
                && $this->revocation_reason === null
                && $this->invalidated_at === null
                && $this->invalidation_reason === null,
            InventoryNegativeOverrideStatus::Consumed =>
                $this->consumed_at !== null,
            InventoryNegativeOverrideStatus::Revoked =>
                $this->revoked_at !== null
                && $this->revoked_by_user_id !== null
                && filled($this->revocation_reason),
            InventoryNegativeOverrideStatus::Invalidated =>
                $this->invalidated_at !== null
                && filled($this->invalidation_reason),
        };

        if (! $valid) {
            throw new DomainException(
                'El estado y los datos del Override no son coherentes.'
            );
        }
    }
}
