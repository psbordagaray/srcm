<?php

namespace App\Models;

use App\Enums\ServiceAssetType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderIntake extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'asset_type_snapshot',
        'brand_name_snapshot',
        'model_name_snapshot',
        'color_snapshot',
        'identifiers_snapshot',
        'customer_name_snapshot',
        'owner_name_snapshot',
        'customer_reported_issue',
        'intake_observations',
        'received_accessories',
        'contact_available',
        'contact_reference',
        'recorded_by_user_id',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException(
                'La fotografía de ingreso de una orden es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La fotografía de ingreso no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'asset_type_snapshot' => ServiceAssetType::class,
            'identifiers_snapshot' => 'array',
            'contact_available' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
