<?php

namespace App\Models;

use App\Enums\FulfillmentUnavailableFallback;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentPreference extends Model
{
    protected $fillable = [
        'organization_id',
        'public_id',
        'inventory_reservation_id',
        'allow_another_brand',
        'allow_equivalent_product',
        'require_exact_product',
        'consultation_required',
        'consultation_channel',
        'unavailable_line_fallback',
        'created_by_user_id',
        'idempotency_key',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'allow_another_brand' => 'boolean',
            'allow_equivalent_product' => 'boolean',
            'require_exact_product' => 'boolean',
            'consultation_required' => 'boolean',
            'unavailable_line_fallback' => FulfillmentUnavailableFallback::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $preference): void {
            $preference->assertCreationInvariants();
        });

        static::updating(function (): void {
            throw new DomainException(
                'La evidencia de preferencias de fulfillment es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La evidencia de preferencias de fulfillment no se elimina físicamente.'
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function forbidsSubstitution(): bool
    {
        return $this->require_exact_product;
    }

    private function assertCreationInvariants(): void
    {
        $idempotencyKey = trim((string) $this->idempotency_key);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 90) {
            throw new DomainException(
                'La clave de idempotencia de las preferencias de fulfillment no es válida.'
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/', (string) $this->fingerprint) !== 1) {
            throw new DomainException(
                'La huella de las preferencias de fulfillment no es válida.'
            );
        }

        $organization = Organization::query()
            ->whereKey($this->organization_id)
            ->where('active', true)
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización de las preferencias de fulfillment no está activa.'
            );
        }

        $reservation = InventoryReservation::query()
            ->whereKey($this->inventory_reservation_id)
            ->where('organization_id', $this->organization_id)
            ->first();

        if (! $reservation || ! $reservation->isEffective()) {
            throw new DomainException(
                'La reserva vinculada a las preferencias debe existir y permanecer efectiva.'
            );
        }

        if (
            $this->require_exact_product
            && ($this->allow_another_brand || $this->allow_equivalent_product)
        ) {
            throw new DomainException(
                'Una preferencia de producto exacto no puede autorizar sustituciones.'
            );
        }

        $channel = $this->consultation_channel;
        $consultationNeeded = $this->consultation_required
            || $this->unavailable_line_fallback === FulfillmentUnavailableFallback::ConsultCustomer;

        if ($consultationNeeded && $channel === null) {
            throw new DomainException(
                'La consulta al cliente requiere un canal de consulta.'
            );
        }

        if ($channel !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $channel) !== 1) {
            throw new DomainException(
                'El canal de consulta de fulfillment no es válido.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $this->organization_id)
            ->where('user_id', $this->created_by_user_id)
            ->where('active', true)
            ->first();

        if (! $membership?->role->canRecordCommerceSale()) {
            throw new DomainException(
                'El usuario no puede registrar preferencias de fulfillment.'
            );
        }
    }
}
