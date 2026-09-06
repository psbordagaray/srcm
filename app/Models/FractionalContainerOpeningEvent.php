<?php

namespace App\Models;

use App\Enums\FractionalContainerState;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FractionalContainerOpeningEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'opening_authorization_id',
        'fractional_container_id',
        'opened_by_user_id',
        'idempotency_key',
        'state_before',
        'state_after',
        'remaining_before',
        'remaining_after',
        'opened_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (
                $event->state_before
                    !== FractionalContainerState::Sealed
                || $event->state_after
                    !== FractionalContainerState::Open
            ) {
                throw new DomainException(
                    'Un evento de apertura debe representar '
                    .'exactamente SELLADO → ABIERTO.'
                );
            }

            if (
                ! \App\Domain\Inventory\InventoryQuantity::equal(
                    $event->remaining_before,
                    $event->remaining_after
                )
            ) {
                throw new DomainException(
                    'Abrir un contenedor no puede alterar '
                    .'su cantidad física.'
                );
            }
        });

        static::updating(function (): void {
            throw new DomainException(
                'Un evento físico de apertura es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un evento físico de apertura no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'state_before' => FractionalContainerState::class,
            'state_after' => FractionalContainerState::class,
            'remaining_before' => 'decimal:6',
            'remaining_after' => 'decimal:6',
            'opened_at' => 'immutable_datetime',
        ];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(
            FractionalContainerOpeningAuthorization::class,
            'opening_authorization_id'
        );
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(
            FractionalContainer::class,
            'fractional_container_id'
        );
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'opened_by_user_id'
        );
    }
}
