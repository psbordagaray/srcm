<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommercePostSaleReceipt extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_request_id',
        'inventory_movement_id',
        'received_by_user_id',
        'received_at',
        'notes',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleReceipt $receipt
        ): void {
            if (blank($receipt->public_id)) {
                $receipt->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una recepción física de posventa confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una recepción física de posventa confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleRequest::class,
            'commerce_post_sale_request_id'
        );
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleReceiptLine::class
        )->orderBy('id');
    }
}
