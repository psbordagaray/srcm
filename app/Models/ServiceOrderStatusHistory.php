<?php

namespace App\Models;

use App\Enums\ServiceOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderStatusHistory extends Model
{
    use BelongsToOrganization;

    protected $table = 'service_order_status_histories';

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'changed_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'La historia de una orden de servicio es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La historia de una orden no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => ServiceOrderStatus::class,
            'to_status' => ServiceOrderStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
