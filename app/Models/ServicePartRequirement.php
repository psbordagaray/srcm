<?php

namespace App\Models;

use App\Enums\InventoryCondition;
use App\Enums\ServicePartSource;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePartRequirement extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'service_work_item_id',
        'service_quote_line_id',
        'catalog_product_id',
        'condition',
        'source',
        'required_quantity',
        'base_unit_code',
        'created_by_user_id',
        'planned_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La necesidad de repuesto es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La necesidad de repuesto no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'source' => ServicePartSource::class,
            'required_quantity' => 'decimal:6',
            'planned_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkItem::class,
            'service_work_item_id'
        );
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteLine::class,
            'service_quote_line_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function purchaseLines(): HasMany
    {
        return $this->hasMany(ServicePartPurchaseLine::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ServicePartConsumption::class);
    }
}
