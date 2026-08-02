<?php

namespace App\Models;

use App\Enums\ServiceWorkCustodyDirection;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWorkCustodyLink extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_work_item_id',
        'service_custody_event_id',
        'direction',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El vínculo de custodia es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'El vínculo de custodia no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return ['direction' => ServiceWorkCustodyDirection::class];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkItem::class,
            'service_work_item_id'
        );
    }

    public function custodyEvent(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCustodyEvent::class,
            'service_custody_event_id'
        );
    }
}
