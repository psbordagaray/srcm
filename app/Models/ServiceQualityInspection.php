<?php

namespace App\Models;

use App\Enums\ServiceQualityOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceQualityInspection extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'revision',
        'outcome',
        'check_count',
        'failed_check_count',
        'checks',
        'condition_notes',
        'accessories_snapshot',
        'rework_reason',
        'notes',
        'inspected_by_user_id',
        'inspected_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El control de calidad es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'El control de calidad no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'outcome' => ServiceQualityOutcome::class,
            'check_count' => 'integer',
            'failed_check_count' => 'integer',
            'checks' => 'array',
            'revision' => 'integer',
            'inspected_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(ServiceDelivery::class);
    }
}
