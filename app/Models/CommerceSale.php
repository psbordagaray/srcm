<?php

namespace App\Models;

use App\Enums\CommerceSaleStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommerceSale extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'sale_number',
        'status',
        'service_order_id',
        'service_delivery_id',
        'service_quote_decision_id',
        'service_quote_option_id',
        'customer_business_party_id',
        'customer_name_snapshot',
        'customer_document_snapshot',
        'currency_code',
        'service_subtotal_minor',
        'product_subtotal_minor',
        'total_minor',
        'inventory_movement_id',
        'notes',
        'recorded_by_user_id',
        'sold_at',
        'confirmed_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommerceSale $sale): void {
            if (blank($sale->public_id)) {
                $sale->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (CommerceSale $sale): void {
            if (
                $sale->getRawOriginal('status')
                    !== CommerceSaleStatus::Building->value
                || $sale->status !== CommerceSaleStatus::Confirmed
                || $sale->isDirty(array_diff(
                    $sale->getFillable(),
                    ['status', 'confirmed_at']
                ))
            ) {
                throw new DomainException(
                    'Una venta sólo puede pasar de preparación a confirmada.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una venta comercial no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => CommerceSaleStatus::class,
            'service_subtotal_minor' => 'integer',
            'product_subtotal_minor' => 'integer',
            'total_minor' => 'integer',
            'sold_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ServiceDelivery::class, 'service_delivery_id');
    }

    public function quoteDecision(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteDecision::class,
            'service_quote_decision_id'
        );
    }

    public function quoteOption(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteOption::class,
            'service_quote_option_id'
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'customer_business_party_id'
        );
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommerceSaleLine::class)
            ->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercePayment::class)
            ->orderBy('position');
    }

    public function postSaleRequests(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleRequest::class
        )->latest('requested_at')->latest('id');
    }
}
