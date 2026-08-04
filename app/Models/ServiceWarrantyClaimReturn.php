<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWarrantyClaimReturn extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_warranty_claim_id',
        'service_warranty_claim_resolution_id',
        'corrective_service_order_id',
        'service_custody_event_id',
        'recipient_business_party_id',
        'recipient_name',
        'recipient_document',
        'condition_notes',
        'accessories_snapshot',
        'notes',
        'returned_by_user_id',
        'returned_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La devolución del reclamo de garantía es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La devolución del reclamo de garantía no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'returned_at' => 'immutable_datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaim::class,
            'service_warranty_claim_id'
        );
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaimResolution::class,
            'service_warranty_claim_resolution_id'
        );
    }

    public function correctiveOrder(): BelongsTo
    {
        return $this->belongsTo(
            ServiceOrder::class,
            'corrective_service_order_id'
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

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }
}
