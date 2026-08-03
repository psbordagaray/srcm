<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePartPurchase extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'supplier_id',
        'currency_code',
        'parts_total_minor',
        'logistics_cost_minor',
        'grand_total_minor',
        'document_reference',
        'notes',
        'purchased_by_user_id',
        'purchased_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La compra afectada es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La compra afectada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'parts_total_minor' => 'integer',
            'logistics_cost_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'purchased_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServicePartPurchaseLine::class)
            ->orderBy('sequence');
    }
}
