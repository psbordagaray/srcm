<?php

namespace App\Models;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTransformationDirection;
use App\Enums\InventoryTransformationOutputRole;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransformationLineage extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'inventory_transformation_id',
        'inventory_movement_line_id',
        'direction',
        'output_role',
        'sequence',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryTransformationLineage $lineage): void {
            if ($lineage->exists && $lineage->isDirty()) {
                throw new DomainException(
                    'La procedencia de una transformación confirmada es inmutable.'
                );
            }

            $lineage->guardDirectionAndReference();
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La procedencia de una transformación confirmada no se elimina físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'direction' => InventoryTransformationDirection::class,
            'output_role' => InventoryTransformationOutputRole::class,
        ];
    }

    public function transformation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryTransformation::class,
            'inventory_transformation_id'
        );
    }

    public function movementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }

    private function guardDirectionAndReference(): void
    {
        if (
            $this->direction === InventoryTransformationDirection::Input
            && $this->output_role !== null
        ) {
            throw new DomainException(
                'Una entrada de transformación no admite rol de salida.'
            );
        }

        if (
            $this->direction === InventoryTransformationDirection::Output
            && $this->output_role === null
        ) {
            throw new DomainException(
                'Una salida de transformación requiere un rol explícito.'
            );
        }

        $transformation = InventoryTransformation::query()
            ->whereKey($this->inventory_transformation_id)
            ->where('organization_id', $this->organization_id)
            ->first();

        $line = InventoryMovementLine::query()
            ->whereKey($this->inventory_movement_line_id)
            ->where('organization_id', $this->organization_id)
            ->with('movement')
            ->first();

        if (! $transformation || ! $line) {
            throw new DomainException(
                'La procedencia debe pertenecer a la misma organización que la transformación.'
            );
        }

        if ($line->movement->status !== InventoryMovementStatus::Confirmed) {
            throw new DomainException(
                'La transformación sólo puede enlazar movimientos confirmados.'
            );
        }

        if ($this->direction === InventoryTransformationDirection::Input) {
            if (
                $line->movement->type !== InventoryMovementType::TransformationInput
                || $line->source_location_id === null
                || $line->destination_location_id !== null
            ) {
                throw new DomainException(
                    'Una entrada debe enlazar una línea confirmada de consumo por transformación.'
                );
            }

            return;
        }

        if (
            $line->movement->type !== InventoryMovementType::TransformationOutput
            || $line->source_location_id !== null
            || $line->destination_location_id === null
        ) {
            throw new DomainException(
                'Una salida debe enlazar una línea confirmada de producción por transformación.'
            );
        }
    }
}
