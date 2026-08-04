<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceDelivery extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'service_quality_inspection_id',
        'service_custody_event_id',
        'recipient_business_party_id',
        'recipient_name',
        'recipient_document',
        'customer_conformity',
        'condition_notes',
        'accessories_snapshot',
        'notes',
        'delivered_by_user_id',
        'delivered_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La entrega de la orden es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La entrega de la orden no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'customer_conformity' => 'boolean',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQualityInspection::class,
            'service_quality_inspection_id'
        );
    }

    public function custodyEvent(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCustodyEvent::class,
            'service_custody_event_id'
        );
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'recipient_business_party_id'
        );
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(ServiceWarrantyGrant::class)
            ->orderBy('id');
    }

    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(
            ServiceWarrantyClaim::class,
            'original_service_delivery_id'
        )->orderBy('id');
    }

    public function commerceSale(): HasOne
    {
        return $this->hasOne(CommerceSale::class);
    }
}
