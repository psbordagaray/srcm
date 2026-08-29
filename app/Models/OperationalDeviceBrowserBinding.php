<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class OperationalDeviceBrowserBinding extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'operational_device_id',
        'public_id',
        'token_hash',
        'issued_by_user_id',
        'issued_at',
        'expires_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (OperationalDeviceBrowserBinding $binding) =>
                $binding->assertInvariants()
        );

        static::updating(
            function (OperationalDeviceBrowserBinding $binding): void {
                foreach ([
                    'organization_id',
                    'operational_device_id',
                    'public_id',
                    'token_hash',
                    'issued_by_user_id',
                    'issued_at',
                    'expires_at',
                ] as $immutable) {
                    if ($binding->isDirty($immutable)) {
                        throw new LogicException(
                            'La identidad y vigencia original del binding del navegador son inmutables.'
                        );
                    }
                }

                if (
                    $binding->getRawOriginal('revoked_at') !== null
                    && $binding->isDirty('revoked_at')
                ) {
                    throw new LogicException(
                        'Un binding del navegador revocado no puede reactivarse ni reescribirse.'
                    );
                }

                $binding->assertInvariants();
            }
        );

        static::deleting(function (): void {
            throw new LogicException(
                'Los bindings de navegador de dispositivos operativos no pueden eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(
            OperationalDevice::class,
            'operational_device_id'
        );
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'issued_by_user_id'
        );
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    private function assertInvariants(): void
    {
        if (
            (int) $this->organization_id < 1
            || (int) $this->operational_device_id < 1
            || (int) $this->issued_by_user_id < 1
        ) {
            throw new DomainException(
                'El binding del navegador requiere organización, dispositivo y emisor.'
            );
        }

        if (! Str::isUuid((string) $this->public_id)) {
            throw new DomainException(
                'El identificador público del binding del navegador es inválido.'
            );
        }

        if (
            preg_match(
                '/^[0-9a-f]{64}$/D',
                (string) $this->token_hash
            ) !== 1
        ) {
            throw new DomainException(
                'La huella de la credencial del binding del navegador es inválida.'
            );
        }

        if (
            ! $this->issued_at
            || ! $this->expires_at
            || $this->expires_at->lessThanOrEqualTo($this->issued_at)
        ) {
            throw new DomainException(
                'La vigencia del binding del navegador es inválida.'
            );
        }

        if (
            $this->revoked_at
            && $this->revoked_at->lessThan($this->issued_at)
        ) {
            throw new DomainException(
                'La revocación del binding no puede preceder a su emisión.'
            );
        }
    }
}
