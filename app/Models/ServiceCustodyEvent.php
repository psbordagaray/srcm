<?php

namespace App\Models;

use App\Enums\ServiceCustodyEventType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCustodyEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'event_type',
        'from_holder_type',
        'from_holder_reference',
        'from_holder_name',
        'to_holder_type',
        'to_holder_reference',
        'to_holder_name',
        'location_id',
        'condition_notes',
        'accessories_snapshot',
        'recorded_by_user_id',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'Los eventos de custodia son inmutables.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un evento de custodia no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => ServiceCustodyEventType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'location_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
