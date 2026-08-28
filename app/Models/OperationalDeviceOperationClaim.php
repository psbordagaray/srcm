<?php

namespace App\Models;

use App\Enums\OperationalDeviceCapability;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OperationalDeviceOperationClaim extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'operational_device_id',
        'client_operation_id',
        'capability',
        'operation_type',
        'request_fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException(
                'Los claims de operaciones de dispositivo son inmutables.'
            );
        });

        static::deleting(function (): void {
            throw new LogicException(
                'Los claims de operaciones de dispositivo no pueden eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'capability' => OperationalDeviceCapability::class,
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(
            OperationalDevice::class,
            'operational_device_id'
        );
    }
}
