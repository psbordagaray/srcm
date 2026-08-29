<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class OperationalDevice extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'label',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (OperationalDevice $device) =>
                $device->assertInvariants()
        );

        static::updating(
            fn (OperationalDevice $device) =>
                $device->assertInvariants()
        );

        static::deleting(function (): void {
            throw new LogicException(
                'Los dispositivos operativos no pueden eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function setLabelAttribute(string $value): void
    {
        $this->attributes['label'] = Str::of($value)
            ->squish()
            ->toString();
    }

    public function capabilityGrants(): HasMany
    {
        return $this->hasMany(
            OperationalDeviceCapabilityGrant::class
        );
    }

    public function operationClaims(): HasMany
    {
        return $this->hasMany(
            OperationalDeviceOperationClaim::class
        );
    }

    private function assertInvariants(): void
    {
        if ((int) $this->organization_id < 1) {
            throw new DomainException(
                'El dispositivo operativo requiere una organización.'
            );
        }

        if (! Str::isUuid((string) $this->public_id)) {
            throw new DomainException(
                'El identificador público del dispositivo es inválido.'
            );
        }

        $label = (string) $this->label;

        if (
            $label === ''
            || Str::length($label) > 120
        ) {
            throw new DomainException(
                'La etiqueta del dispositivo operativo es inválida.'
            );
        }

        if (
            $this->exists
            && $this->isDirty('organization_id')
        ) {
            throw new DomainException(
                'La organización de un dispositivo operativo no puede cambiarse.'
            );
        }

        if (
            $this->exists
            && $this->isDirty('public_id')
        ) {
            throw new DomainException(
                'El identificador público de un dispositivo operativo es inmutable.'
            );
        }
    }
}
