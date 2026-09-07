<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryCondition;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariableQuantityFulfillment extends Model
{
    protected $fillable = [
        'organization_id','public_id','inventory_reservation_id',
        'catalog_product_id','inventory_location_id','condition',
        'requested_quantity','measured_quantity','accepted_quantity',
        'base_unit_code','created_by_user_id','idempotency_key','fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'requested_quantity' => 'decimal:6',
            'measured_quantity' => 'decimal:6',
            'accepted_quantity' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $fulfillment): void {
            $fulfillment->assertCreationInvariants();
        });

        static::updating(function (): void {
            throw new DomainException(
                'La evidencia de fulfillment de cantidad variable es inmutable.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La evidencia de fulfillment de cantidad variable no se elimina físicamente.'
            );
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isVariable(): bool
    {
        return ! InventoryQuantity::equal(
            $this->requested_quantity,
            $this->accepted_quantity
        );
    }

    private function assertCreationInvariants(): void
    {
        $idempotencyKey = trim((string) $this->idempotency_key);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 90) {
            throw new DomainException(
                'La clave de idempotencia del fulfillment no es válida.'
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/', (string) $this->fingerprint) !== 1) {
            throw new DomainException(
                'La huella del fulfillment no es válida.'
            );
        }

        $organization = Organization::query()
            ->whereKey($this->organization_id)
            ->where('active', true)
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización del fulfillment no está activa.'
            );
        }

        $product = CatalogProduct::query()
            ->whereKey($this->catalog_product_id)
            ->where('active', true)
            ->first();

        if (! $product || ! $product->allowsFractionalQuantity()) {
            throw new DomainException(
                'El fulfillment de cantidad variable requiere un producto fraccionable activo.'
            );
        }

        $location = InventoryLocation::query()
            ->whereKey($this->inventory_location_id)
            ->where('organization_id', $this->organization_id)
            ->where('active', true)
            ->first();

        if (! $location) {
            throw new DomainException(
                'La ubicación del fulfillment no pertenece a la organización o está inactiva.'
            );
        }

        $requested = InventoryQuantity::positive(
            $this->requested_quantity,
            InventoryQuantity::SCALE,
            'La cantidad solicitada'
        );
        $measured = InventoryQuantity::positive(
            $this->measured_quantity,
            InventoryQuantity::SCALE,
            'La cantidad medida'
        );
        $accepted = InventoryQuantity::positive(
            $this->accepted_quantity,
            InventoryQuantity::SCALE,
            'La cantidad aceptada'
        );

        foreach ([
            'La cantidad solicitada' => $requested,
            'La cantidad medida' => $measured,
            'La cantidad aceptada' => $accepted,
        ] as $label => $quantity) {
            InventoryQuantity::assertFitsScale(
                $quantity,
                (int) $product->quantity_scale,
                $label
            );
        }

        if (! InventoryQuantity::equal($measured, $accepted)) {
            throw new DomainException(
                'La cantidad aceptada debe coincidir exactamente con la medición física registrada.'
            );
        }

        if ((string) $this->base_unit_code !== (string) $product->base_unit_code) {
            throw new DomainException(
                'La unidad base del fulfillment no coincide con el producto.'
            );
        }

        if ($this->inventory_reservation_id !== null) {
            $reservation = InventoryReservation::query()
                ->whereKey($this->inventory_reservation_id)
                ->where('organization_id', $this->organization_id)
                ->first();

            if (! $reservation || ! $reservation->isEffective()) {
                throw new DomainException(
                    'La reserva vinculada debe existir y permanecer efectiva.'
                );
            }

            if (
                (int) $reservation->catalog_product_id !== (int) $this->catalog_product_id
                || (int) $reservation->inventory_location_id !== (int) $this->inventory_location_id
                || $reservation->condition !== $this->condition
                || (string) $reservation->base_unit_code !== (string) $this->base_unit_code
            ) {
                throw new DomainException(
                    'La reserva vinculada no coincide con la posición física del fulfillment.'
                );
            }

            if (! InventoryQuantity::equal($reservation->quantity, $requested)) {
                throw new DomainException(
                    'La cantidad solicitada debe coincidir con la intención reservada.'
                );
            }
        }

        $this->attributes['idempotency_key'] = $idempotencyKey;
        $this->attributes['requested_quantity'] = $requested;
        $this->attributes['measured_quantity'] = $measured;
        $this->attributes['accepted_quantity'] = $accepted;
    }
}
