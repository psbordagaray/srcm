<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FractionalContainerOpeningAuthorization extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'inventory_location_id',
        'condition',
        'authorized_by_user_id',
        'valid_from',
        'valid_until',
        'max_concurrent_open_containers',
        'max_new_openings',
        'target_ready_open_count',
        'idempotency_key',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'Una autorización operativa de apertura es inmutable; '
                .'su revocación sólo puede ejecutarse por el manager.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una autorización operativa de apertura '
                .'no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'max_concurrent_open_containers' => 'integer',
            'max_new_openings' => 'integer',
            'target_ready_open_count' => 'integer',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'inventory_location_id'
        );
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'authorized_by_user_id'
        );
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'revoked_by_user_id'
        );
    }

    public function exactContainers(): BelongsToMany
    {
        return $this->belongsToMany(
            FractionalContainer::class,
            'fractional_container_opening_authorization_containers',
            'opening_authorization_id',
            'fractional_container_id'
        )->withTimestamps();
    }

    public function openingEvents(): HasMany
    {
        return $this->hasMany(
            FractionalContainerOpeningEvent::class,
            'opening_authorization_id'
        );
    }
}
