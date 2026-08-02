<?php

namespace App\Models;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class InventoryMovement extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'organization_id',
        'public_id',
        'type',
        'status',
        'created_by_user_id',
        'confirmed_by_user_id',
        'effective_at',
        'confirmed_at',
        'reason',
        'source_type',
        'source_id',
        'source_reference',
        'idempotency_key',
        'reverses_movement_id',
        'replaces_movement_id',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement): void {
            if (blank($movement->public_id)) {
                $movement->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (InventoryMovement $movement): void {
            $movement->guardImmutableRecord();
            $movement->guardOrganization();
            $movement->guardCorrectionLinks();
            $movement->guardStatusMetadata();
        });

        static::deleting(function (InventoryMovement $movement): void {
            if ($movement->status !== InventoryMovementStatus::Draft) {
                throw new DomainException(
                    'Solo un movimiento borrador puede eliminarse físicamente.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'status' => InventoryMovementStatus::class,
            'effective_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryMovementLine::class)
            ->orderBy('sequence');
    }

    public function negativeAuthorizationRequest(): HasOne
    {
        return $this->hasOne(
            InventoryNegativeRequest::class,
            'inventory_movement_id'
        )->latestOfMany();
    }

    public function inventoryNegativeOverrides(): HasMany
    {
        return $this->hasMany(
            InventoryNegativeOverride::class,
            'inventory_movement_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'reverses_movement_id'
        );
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'replaces_movement_id'
        );
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(
            InventoryMovement::class,
            'reverses_movement_id'
        );
    }

    public function replacement(): HasOne
    {
        return $this->hasOne(
            InventoryMovement::class,
            'replaces_movement_id'
        );
    }

    public function isConfirmed(): bool
    {
        return $this->status === InventoryMovementStatus::Confirmed;
    }

    private function guardImmutableRecord(): void
    {
        if (! $this->exists) {
            return;
        }

        $originalStatus = InventoryMovementStatus::tryFrom(
            (string) $this->getRawOriginal('status')
        );

        if (
            $originalStatus === InventoryMovementStatus::Confirmed
            && $this->isDirty()
        ) {
            throw new DomainException(
                'Un movimiento confirmado es inmutable.'
            );
        }

        if (
            $originalStatus === InventoryMovementStatus::Cancelled
            && $this->isDirty()
        ) {
            throw new DomainException(
                'Un movimiento cancelado es inmutable.'
            );
        }
    }

    private function guardOrganization(): void
    {
        if (
            $this->exists
            && $this->isDirty('organization_id')
        ) {
            throw new DomainException(
                'La organización del movimiento es inmutable.'
            );
        }
    }

    private function guardCorrectionLinks(): void
    {
        if (
            $this->reverses_movement_id !== null
            && $this->replaces_movement_id !== null
        ) {
            throw new DomainException(
                'Un movimiento no puede ser reverso y reemplazo simultáneamente.'
            );
        }

        foreach ([
            'reverses_movement_id',
            'replaces_movement_id',
        ] as $foreignKey) {
            $relatedId = $this->getAttribute($foreignKey);

            if ($relatedId === null) {
                continue;
            }

            if ($this->exists && (int) $relatedId === (int) $this->getKey()) {
                throw new DomainException(
                    'Un movimiento no puede corregirse a sí mismo.'
                );
            }

            $matches = InventoryMovement::query()
                ->whereKey($relatedId)
                ->where('organization_id', $this->organization_id)
                ->where('status', InventoryMovementStatus::Confirmed->value)
                ->exists();

            if (! $matches) {
                throw new DomainException(
                    'El movimiento corregido debe estar confirmado y pertenecer a la misma organización.'
                );
            }
        }

        if (
            $this->reverses_movement_id !== null
            && $this->type !== InventoryMovementType::Reversal
        ) {
            throw new DomainException(
                'Un reverso debe utilizar el tipo de movimiento reverso.'
            );
        }

        if (
            $this->type === InventoryMovementType::Reversal
            && $this->reverses_movement_id === null
        ) {
            throw new DomainException(
                'Un movimiento reverso debe identificar al movimiento original.'
            );
        }
    }

    private function guardStatusMetadata(): void
    {
        if ($this->status === InventoryMovementStatus::Confirmed) {
            if (
                $this->confirmed_at === null
                || $this->confirmed_by_user_id === null
            ) {
                throw new DomainException(
                    'La confirmación requiere fecha y responsable.'
                );
            }

            return;
        }

        if (
            $this->confirmed_at !== null
            || $this->confirmed_by_user_id !== null
        ) {
            throw new DomainException(
                'Solo un movimiento confirmado posee datos de confirmación.'
            );
        }
    }
}
