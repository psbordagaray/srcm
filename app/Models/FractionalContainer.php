<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FractionalContainer extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'product_presentation_id',
        'inventory_location_id',
        'received_inventory_movement_line_id',
        'container_code',
        'condition',
        'state',
        'original_base_quantity',
        'remaining_base_quantity',
        'base_unit_code',
        'base_quantity_scale',
    ];

    protected static function booted(): void
    {
        static::creating(function (FractionalContainer $container): void {
            $container->assertCreationInvariants();
        });

        static::updating(function (): void {
            throw new DomainException(
                'Fractional Container Foundation V1 no habilita mutaciones '
                .'de estado, saldo ni origen físico.'
            );
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un contenedor físico fraccionable no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'state' => FractionalContainerState::class,
            'original_base_quantity' => 'decimal:6',
            'remaining_base_quantity' => 'decimal:6',
            'base_quantity_scale' => 'integer',
        ];
    }

    public function setContainerCodeAttribute(string $value): void
    {
        $code = Str::of($value)->squish()->upper()->toString();

        $this->attributes['container_code'] = $code;
        $this->attributes['normalized_container_code'] =
            static::normalizeContainerCode($code);
    }

    public static function normalizeContainerCode(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(
            ProductPresentation::class,
            'product_presentation_id'
        );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'inventory_location_id'
        );
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'received_inventory_movement_line_id'
        );
    }

    public function isSealed(): bool
    {
        return $this->state === FractionalContainerState::Sealed;
    }

    private function assertCreationInvariants(): void
    {
        $code = (string) $this->container_code;
        $normalized = (string) $this->normalized_container_code;

        if (
            $code === ''
            || Str::length($code) > 80
            || $normalized === ''
            || Str::length($normalized) > 80
        ) {
            throw new DomainException(
                'El código físico del contenedor no es válido.'
            );
        }

        $organization = Organization::query()
            ->whereKey($this->organization_id)
            ->where('active', true)
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización del contenedor no está activa.'
            );
        }

        $product = CatalogProduct::query()
            ->whereKey($this->catalog_product_id)
            ->where('active', true)
            ->first();

        if (! $product || ! $product->allowsFractionalQuantity()) {
            throw new DomainException(
                'El contenedor requiere un producto activo fraccionable.'
            );
        }

        $location = InventoryLocation::query()
            ->whereKey($this->inventory_location_id)
            ->where('organization_id', $this->organization_id)
            ->where('active', true)
            ->first();

        if (! $location) {
            throw new DomainException(
                'La ubicación activa del contenedor no pertenece '
                .'a la organización.'
            );
        }

        $quantity = InventoryQuantity::positive(
            $this->original_base_quantity,
            InventoryQuantity::SCALE,
            'La cantidad original del contenedor'
        );

        InventoryQuantity::assertFitsScale(
            $quantity,
            (int) $product->quantity_scale,
            'La cantidad original del contenedor'
        );

        $remaining = InventoryQuantity::positive(
            $this->remaining_base_quantity,
            InventoryQuantity::SCALE,
            'El saldo fraccionable del contenedor'
        );

        InventoryQuantity::assertFitsScale(
            $remaining,
            (int) $product->quantity_scale,
            'El saldo fraccionable del contenedor'
        );

        if (! InventoryQuantity::equal($quantity, $remaining)) {
            throw new DomainException(
                'Un contenedor nuevo debe iniciar con su saldo físico completo.'
            );
        }

        if ($this->received_inventory_movement_line_id !== null) {
            $receiptLine = InventoryMovementLine::query()
                ->whereKey(
                    $this->received_inventory_movement_line_id
                )
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'catalog_product_id',
                    $this->catalog_product_id
                )
                ->where(
                    'destination_location_id',
                    $this->inventory_location_id
                )
                ->whereNull('source_location_id')
                ->where(
                    'condition',
                    $this->condition->value
                )
                ->where(
                    'base_unit_code',
                    $this->base_unit_code
                )
                ->first();

            if (! $receiptLine) {
                throw new DomainException(
                    'La procedencia del contenedor no coincide con '
                    .'la línea de recepción física.'
                );
            }

            $receiptMovement = $receiptLine->movement()->first();

            if (
                ! $receiptMovement
                || $receiptMovement->type
                    !== InventoryMovementType::Receipt
                || $receiptMovement->status
                    !== InventoryMovementStatus::Confirmed
            ) {
                throw new DomainException(
                    'La procedencia física requiere una recepción '
                    .'confirmada del ledger.'
                );
            }

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $quantity,
                    $receiptLine->base_quantity
                )
            ) {
                throw new DomainException(
                    'El contenedor no puede exceder la cantidad '
                    .'base de su línea de recepción.'
                );
            }
        }

        if ($this->state !== FractionalContainerState::Sealed) {
            throw new DomainException(
                'Fractional Container Foundation V1 registra '
                .'contenedores inicialmente sellados.'
            );
        }

        if (
            (string) $this->base_unit_code
                !== (string) $product->base_unit_code
            || (int) $this->base_quantity_scale
                !== (int) $product->quantity_scale
        ) {
            throw new DomainException(
                'La unidad base del contenedor no coincide con el producto.'
            );
        }

        if ($this->product_presentation_id !== null) {
            $presentation = ProductPresentation::query()
                ->whereKey($this->product_presentation_id)
                ->where('organization_id', $this->organization_id)
                ->where('catalog_product_id', $this->catalog_product_id)
                ->where('active', true)
                ->first();

            if (! $presentation) {
                throw new DomainException(
                    'La presentación física no pertenece al mismo '
                    .'producto y organización.'
                );
            }

            $presentationQuantity = $presentation->toBaseQuantity('1');

            if (
                ! InventoryQuantity::equal(
                    $presentationQuantity,
                    $quantity
                )
            ) {
                throw new DomainException(
                    'La presentación física no representa la capacidad '
                    .'original del contenedor.'
                );
            }
        }

        $this->attributes['original_base_quantity'] = $quantity;
        $this->attributes['remaining_base_quantity'] = $remaining;
    }
}
