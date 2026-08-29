<?php

namespace App\Models;

use App\Enums\OperationalDeviceCapability;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OperationalDeviceCapabilityGrant extends Model
{
    use BelongsToOrganization;

    protected $table = 'operational_device_capabilities';

    protected $fillable = [
        'organization_id',
        'operational_device_id',
        'capability',
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (OperationalDeviceCapabilityGrant $grant) =>
                $grant->assertInvariants()
        );

        static::updating(function (): void {
            throw new LogicException(
                'Las capacidades operativas concedidas son inmutables.'
            );
        });

        static::deleting(function (): void {
            throw new LogicException(
                'Las capacidades operativas concedidas no pueden eliminarse físicamente.'
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

    private function assertInvariants(): void
    {
        $organizationId = (int) $this->organization_id;
        $deviceId = (int) $this->operational_device_id;

        if (
            $organizationId < 1
            || $deviceId < 1
        ) {
            throw new DomainException(
                'La capacidad operativa requiere organización y dispositivo.'
            );
        }

        $matches = OperationalDevice::query()
            ->forOrganization($organizationId)
            ->whereKey($deviceId)
            ->exists();

        if (! $matches) {
            throw new DomainException(
                'El dispositivo no pertenece a la organización de la capacidad.'
            );
        }
    }
}
