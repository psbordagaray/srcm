<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryTransformationDirection;
use App\Models\Concerns\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryTransformation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'effective_at',
        'created_by_user_id',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryTransformation $transformation): void {
            if (blank($transformation->public_id)) {
                $transformation->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (InventoryTransformation $transformation): void {
            if ($transformation->exists && $transformation->isDirty()) {
                throw new DomainException(
                    'La evidencia de transformación confirmada es inmutable.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'La evidencia de transformación confirmada no se elimina físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function lineages(): HasMany
    {
        return $this->hasMany(InventoryTransformationLineage::class)
            ->orderBy('direction')
            ->orderBy('sequence');
    }

    public function inputLineages(): HasMany
    {
        return $this->hasMany(InventoryTransformationLineage::class)
            ->where('direction', InventoryTransformationDirection::Input->value)
            ->orderBy('sequence');
    }

    public function outputLineages(): HasMany
    {
        return $this->hasMany(InventoryTransformationLineage::class)
            ->where('direction', InventoryTransformationDirection::Output->value)
            ->orderBy('sequence');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array{
     *     supported: bool,
     *     base_unit_code: ?string,
     *     input_total: ?string,
     *     output_total: ?string,
     *     yield_ratio: ?string,
     *     residual_quantity: ?string
     * }
     */
    public function quantitySummary(): array
    {
        $lineages = $this->lineages()
            ->with('movementLine')
            ->get();

        $input = $lineages->where(
            'direction',
            InventoryTransformationDirection::Input
        );
        $output = $lineages->where(
            'direction',
            InventoryTransformationDirection::Output
        );

        if ($input->isEmpty() || $output->isEmpty()) {
            return $this->unsupportedQuantitySummary();
        }

        $units = $lineages
            ->map(
                static fn (InventoryTransformationLineage $lineage): string =>
                    (string) $lineage->movementLine->base_unit_code
            )
            ->unique()
            ->values();

        if ($units->count() !== 1) {
            return $this->unsupportedQuantitySummary();
        }

        $inputTotal = InventoryQuantity::signed('0');
        $outputTotal = InventoryQuantity::signed('0');

        foreach ($input as $lineage) {
            $inputTotal = InventoryQuantity::add(
                $inputTotal,
                $lineage->movementLine->base_quantity
            );
        }

        foreach ($output as $lineage) {
            $outputTotal = InventoryQuantity::add(
                $outputTotal,
                $lineage->movementLine->base_quantity
            );
        }

        if (! InventoryQuantity::isPositive($inputTotal)) {
            return $this->unsupportedQuantitySummary();
        }

        $ratio = (string) BigDecimal::of($outputTotal)->dividedBy(
            BigDecimal::of($inputTotal),
            6,
            RoundingMode::HalfUp
        );

        return [
            'supported' => true,
            'base_unit_code' => (string) $units->first(),
            'input_total' => $inputTotal,
            'output_total' => $outputTotal,
            'yield_ratio' => $ratio,
            'residual_quantity' => InventoryQuantity::subtract(
                $inputTotal,
                $outputTotal
            ),
        ];
    }

    /**
     * @return array{
     *     supported: false,
     *     base_unit_code: null,
     *     input_total: null,
     *     output_total: null,
     *     yield_ratio: null,
     *     residual_quantity: null
     * }
     */
    private function unsupportedQuantitySummary(): array
    {
        return [
            'supported' => false,
            'base_unit_code' => null,
            'input_total' => null,
            'output_total' => null,
            'yield_ratio' => null,
            'residual_quantity' => null,
        ];
    }
}
