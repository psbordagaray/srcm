<?php

namespace App\Models;

use App\Enums\ServiceWorkOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWorkReport extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_work_item_id',
        'outcome',
        'result_summary',
        'work_performed',
        'unresolved_reason',
        'warranty_days',
        'warranty_terms',
        'recorded_by_user_id',
        'recorded_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El resultado técnico es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'El resultado técnico no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'outcome' => ServiceWorkOutcome::class,
            'warranty_days' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkItem::class,
            'service_work_item_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
