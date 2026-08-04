<?php

namespace App\Models;

use App\Enums\ServiceWarrantyClaimStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWarrantyClaimStatusHistory extends Model
{
    use BelongsToOrganization;

    protected $table = 'service_warranty_claim_status_histories';

    protected $fillable = [
        'organization_id',
        'service_warranty_claim_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'changed_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La historia del reclamo de garantía es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La historia del reclamo de garantía no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'from_status' => ServiceWarrantyClaimStatus::class,
            'to_status' => ServiceWarrantyClaimStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaim::class,
            'service_warranty_claim_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
