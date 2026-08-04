<?php

namespace App\Models;

use App\Enums\ServiceCancellationReason;
use App\Enums\ServiceOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceCancellationRequest extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'reason',
        'requester_business_party_id',
        'requester_name',
        'customer_reference',
        'channel',
        'details',
        'order_status_snapshot',
        'has_started_work',
        'has_part_purchases',
        'has_part_consumptions',
        'has_external_custody',
        'has_registered_payments',
        'exposure_snapshot',
        'requested_by_user_id',
        'requested_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La solicitud de cancelación es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La solicitud de cancelación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'reason' => ServiceCancellationReason::class,
            'order_status_snapshot' => ServiceOrderStatus::class,
            'has_started_work' => 'boolean',
            'has_part_purchases' => 'boolean',
            'has_part_consumptions' => 'boolean',
            'has_external_custody' => 'boolean',
            'has_registered_payments' => 'boolean',
            'exposure_snapshot' => 'array',
            'requested_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'requester_business_party_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolution(): HasOne
    {
        return $this->hasOne(ServiceCancellationResolution::class);
    }
}
