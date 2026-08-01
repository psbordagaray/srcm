<?php

namespace App\Models;

use App\Enums\InventoryNegativeRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class InventoryNegativeRequest extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id',
        'public_id',
        'inventory_movement_id',
        'requested_by_user_id',
        'status',
        'reason',
        'movement_fingerprint',
        'snapshot_fingerprint',
        'request_fingerprint',
        'requested_at',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
        'invalidated_at',
        'invalidation_reason',
        'fulfilled_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (blank($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (self $request): void {
            $request->guardImmutableCore();
            $request->guardTransition();
            $request->guardStatusMetadata();
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una solicitud de stock negativo no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InventoryNegativeRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryNegativeRequestLine::class)
            ->orderBy('sequence');
    }

    public function override(): HasOne
    {
        return $this->hasOne(InventoryNegativeOverride::class);
    }

    private function guardImmutableCore(): void
    {
        if (! $this->exists) {
            return;
        }

        if ($this->isDirty([
            'organization_id',
            'public_id',
            'inventory_movement_id',
            'requested_by_user_id',
            'reason',
            'movement_fingerprint',
            'snapshot_fingerprint',
            'request_fingerprint',
            'requested_at',
        ])) {
            throw new DomainException(
                'El contenido de una solicitud de stock negativo es inmutable.'
            );
        }
    }

    private function guardTransition(): void
    {
        if (! $this->exists || ! $this->isDirty('status')) {
            return;
        }

        $from = InventoryNegativeRequestStatus::tryFrom(
            (string) $this->getRawOriginal('status')
        );
        $allowed = match ($from) {
            InventoryNegativeRequestStatus::Pending => [
                InventoryNegativeRequestStatus::Approved,
                InventoryNegativeRequestStatus::Rejected,
                InventoryNegativeRequestStatus::Invalidated,
            ],
            InventoryNegativeRequestStatus::Approved => [
                InventoryNegativeRequestStatus::Fulfilled,
                InventoryNegativeRequestStatus::Invalidated,
            ],
            default => [],
        };

        if (! in_array($this->status, $allowed, true)) {
            throw new DomainException(
                'La transición de la solicitud de stock negativo es inválida.'
            );
        }
    }

    private function guardStatusMetadata(): void
    {
        $valid = match ($this->status) {
            InventoryNegativeRequestStatus::Pending =>
                $this->approved_at === null
                && $this->approved_by_user_id === null
                && $this->rejected_at === null
                && $this->rejected_by_user_id === null
                && $this->rejection_reason === null
                && $this->invalidated_at === null
                && $this->invalidation_reason === null
                && $this->fulfilled_at === null,
            InventoryNegativeRequestStatus::Approved =>
                $this->approved_at !== null
                && $this->approved_by_user_id !== null
                && $this->fulfilled_at === null,
            InventoryNegativeRequestStatus::Rejected =>
                $this->rejected_at !== null
                && $this->rejected_by_user_id !== null
                && filled($this->rejection_reason),
            InventoryNegativeRequestStatus::Invalidated =>
                $this->invalidated_at !== null
                && filled($this->invalidation_reason),
            InventoryNegativeRequestStatus::Fulfilled =>
                $this->approved_at !== null
                && $this->approved_by_user_id !== null
                && $this->fulfilled_at !== null,
        };

        if (! $valid) {
            throw new DomainException(
                'El estado y los datos de la solicitud no son coherentes.'
            );
        }
    }
}
