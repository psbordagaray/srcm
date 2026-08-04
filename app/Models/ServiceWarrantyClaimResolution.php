<?php

namespace App\Models;

use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyTemporalStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceWarrantyClaimResolution extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_warranty_claim_id',
        'outcome',
        'technical_basis',
        'covered_scope',
        'excluded_scope',
        'warranty_status_at_resolution',
        'administrative_exception',
        'exception_reason',
        'notes',
        'resolved_by_user_id',
        'resolved_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La resolución del reclamo de garantía es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La resolución del reclamo de garantía no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'outcome' => ServiceWarrantyClaimOutcome::class,
            'warranty_status_at_resolution' => ServiceWarrantyTemporalStatus::class,
            'administrative_exception' => 'boolean',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaim::class,
            'service_warranty_claim_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(
            ServiceWorkItem::class,
            'service_warranty_claim_resolution_id'
        );
    }

    public function partRequirements(): HasMany
    {
        return $this->hasMany(
            ServicePartRequirement::class,
            'service_warranty_claim_resolution_id'
        );
    }

    public function returnRecord(): HasOne
    {
        return $this->hasOne(ServiceWarrantyClaimReturn::class);
    }
}
