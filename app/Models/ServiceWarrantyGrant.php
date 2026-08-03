<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWarrantyGrant extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_delivery_id',
        'service_work_report_id',
        'warranty_days',
        'coverage_terms',
        'starts_at',
        'expires_at',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La garantía otorgada es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La garantía otorgada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'warranty_days' => 'integer',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ServiceDelivery::class);
    }

    public function workReport(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkReport::class,
            'service_work_report_id'
        );
    }
}
