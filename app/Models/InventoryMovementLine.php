<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class InventoryMovementLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_movement_id',
        'sequence',
        'catalog_product_id',
        'condition',
        'source_location_id',
        'destination_location_id',
        'entered_quantity',
        'entered_unit_code',
        'conversion_factor',
        'base_quantity',
        'base_unit_code',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryMovementLine $line): void {
            $line->guardOrganization();
            $movement = $line->guardDraftMovement();
            $line->guardLocations($movement);
            $line->guardQuantities();
            $line->guardProductUnit();
        });

        static::deleting(function (InventoryMovementLine $line): void {
            $line->guardDraftMovement();
        });
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'entered_quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:8',
            'base_quantity' => 'decimal:6',
        ];
    }

    public function setEnteredUnitCodeAttribute(string $value): void
    {
        $this->attributes['entered_unit_code'] = Str::lower(trim($value));
    }

    public function setBaseUnitCodeAttribute(string $value): void
    {
        $this->attributes['base_unit_code'] = Str::lower(trim($value));
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'source_location_id'
        );
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'destination_location_id'
        );
    }

    public function servicePartConsumption(): HasOne
    {
        return $this->hasOne(ServicePartConsumption::class);
    }

    public function commerceSaleLine(): HasOne
    {
        return $this->hasOne(
            CommerceSaleLine::class,
            'inventory_movement_line_id'
        );
    }

    private function guardOrganization(): void
    {
        if (
            $this->exists
            && $this->isDirty('organization_id')
        ) {
            throw new DomainException(
                'La organización de la línea es inmutable.'
            );
        }
    }

    private function guardDraftMovement(): InventoryMovement
    {
        $movement = InventoryMovement::query()
            ->whereKey($this->inventory_movement_id)
            ->where('organization_id', $this->organization_id)
            ->first();

        if (! $movement) {
            throw new DomainException(
                'El movimiento no pertenece a la organización de la línea.'
            );
        }

        if ($movement->status !== InventoryMovementStatus::Draft) {
            throw new DomainException(
                'Las líneas de un movimiento confirmado o cancelado son inmutables.'
            );
        }

        return $movement;
    }

    private function guardLocations(InventoryMovement $movement): void
    {
        if (
            $this->source_location_id === null
            && $this->destination_location_id === null
        ) {
            throw new DomainException(
                'La línea requiere una ubicación de origen o destino.'
            );
        }

        if (
            $this->source_location_id !== null
            && (int) $this->source_location_id
                === (int) $this->destination_location_id
        ) {
            throw new DomainException(
                'El origen y el destino deben ser diferentes.'
            );
        }

        foreach ([
            'source_location_id',
            'destination_location_id',
        ] as $foreignKey) {
            $locationId = $this->getAttribute($foreignKey);

            if ($locationId === null) {
                continue;
            }

            $matches = InventoryLocation::query()
                ->whereKey($locationId)
                ->where('organization_id', $this->organization_id)
                ->exists();

            if (! $matches) {
                throw new DomainException(
                    'La ubicación no pertenece a la organización del movimiento.'
                );
            }
        }

        if (
            $movement->type->requiresSource()
            && $this->source_location_id === null
        ) {
            throw new DomainException(
                'El tipo de movimiento requiere una ubicación de origen.'
            );
        }

        if (
            $movement->type->requiresDestination()
            && $this->destination_location_id === null
        ) {
            throw new DomainException(
                'El tipo de movimiento requiere una ubicación de destino.'
            );
        }

        if (
            ! $movement->type->allowsSource()
            && $this->source_location_id !== null
        ) {
            throw new DomainException(
                'El tipo de movimiento no admite una ubicación de origen.'
            );
        }

        if (
            ! $movement->type->allowsDestination()
            && $this->destination_location_id !== null
        ) {
            throw new DomainException(
                'El tipo de movimiento no admite una ubicación de destino.'
            );
        }
    }

    private function guardQuantities(): void
    {
        $attributes = $this->getAttributes();
        $enteredQuantity = InventoryQuantity::positive(
            $attributes['entered_quantity'] ?? null
        );
        $conversionFactor = InventoryQuantity::factor(
            $attributes['conversion_factor'] ?? null
        );
        $baseQuantity = InventoryQuantity::positive(
            $attributes['base_quantity'] ?? null
        );

        InventoryQuantity::assertEquivalent(
            $enteredQuantity,
            $conversionFactor,
            $baseQuantity
        );

        $this->attributes['entered_quantity'] = $enteredQuantity;
        $this->attributes['conversion_factor'] = $conversionFactor;
        $this->attributes['base_quantity'] = $baseQuantity;

        if (
            blank($this->entered_unit_code)
            || blank($this->base_unit_code)
        ) {
            throw new DomainException(
                'La línea debe conservar la unidad ingresada y la unidad base.'
            );
        }
    }

    private function guardProductUnit(): void
    {
        $product = CatalogProduct::query()
            ->whereKey($this->catalog_product_id)
            ->first();

        if (! $product) {
            throw new DomainException('El producto no existe.');
        }

        if ($product->base_unit_code !== $this->base_unit_code) {
            throw new DomainException(
                'La unidad base de la línea no coincide con la del producto.'
            );
        }

        InventoryQuantity::assertFitsScale(
            $this->base_quantity,
            (int) $product->quantity_scale
        );
    }
}
