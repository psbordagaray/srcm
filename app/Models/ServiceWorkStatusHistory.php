<?php

namespace App\Models;

use App\Enums\ServiceWorkStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWorkStatusHistory extends Model
{
    use BelongsToOrganization;

    protected $table = 'service_work_status_histories';

    protected $fillable = [
        'organization_id',
        'service_work_item_id',
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
            'La historia del trabajo es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La historia del trabajo no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'from_status' => ServiceWorkStatus::class,
            'to_status' => ServiceWorkStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkItem::class,
            'service_work_item_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
