<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductPresentation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'unit_code',
        'name',
        'quantity_scale',
        'conversion_factor',
        'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductPresentation $presentation): void {
            $presentation->unit_code = static::normalizeUnitCode(
                (string) $presentation->unit_code
            );
            $presentation->name = static::normalizeName(
                (string) $presentation->name
            );

            $scale = (int) $presentation->quantity_scale;

            if (
                $presentation->unit_code === ''
                || Str::length($presentation->unit_code) > 16
                || preg_match(
                    '/^[a-z0-9][a-z0-9._-]{0,15}$/',
                    $presentation->unit_code
                ) !== 1
            ) {
                throw new DomainException(
                    'El código de presentación no es válido.'
                );
            }

            if (
                $presentation->name === ''
                || Str::length($presentation->name) > 120
            ) {
                throw new DomainException(
                    'El nombre de la presentación no es válido.'
                );
            }

            if ($scale < 0 || $scale > InventoryQuantity::SCALE) {
                throw new DomainException(
                    'La precisión de la presentación debe estar entre 0 y '
                    .InventoryQuantity::SCALE.'.'
                );
            }

            if (
                $presentation->exists
                && $presentation->isDirty([
                    'organization_id',
                    'catalog_product_id',
                    'unit_code',
                    'quantity_scale',
                    'conversion_factor',
                    'base_unit_code',
                    'base_quantity_scale',
                ])
            ) {
                throw new DomainException(
                    'El contrato cuantitativo de una presentación es inmutable.'
                );
            }

            if ($presentation->exists) {
                return;
            }

            $organization = Organization::query()
                ->whereKey($presentation->organization_id)
                ->where('active', true)
                ->first();

            if (! $organization) {
                throw new DomainException(
                    'La organización de la presentación no está activa.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($presentation->catalog_product_id)
                ->where('active', true)
                ->first();

            if (! $product) {
                throw new DomainException(
                    'El producto de la presentación no está activo.'
                );
            }

            $factor = InventoryQuantity::factor(
                $presentation->conversion_factor
            );
            $onePresentation = InventoryQuantity::multiply('1', $factor);

            InventoryQuantity::assertFitsScale(
                $onePresentation,
                (int) $product->quantity_scale,
                'La conversión de una presentación'
            );

            $presentation->quantity_scale = $scale;
            $presentation->conversion_factor = $factor;
            $presentation->base_unit_code = $product->base_unit_code;
            $presentation->base_quantity_scale =
                (int) $product->quantity_scale;
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_scale' => 'integer',
            'conversion_factor' => 'decimal:8',
            'base_quantity_scale' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function normalizeUnitCode(string $value): string
    {
        return Str::lower(trim($value));
    }

    public static function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->toString();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function toBaseQuantity(mixed $enteredQuantity): string
    {
        if (! $this->active) {
            throw new DomainException(
                'La presentación está inactiva.'
            );
        }

        $product = $this->product()->first();

        if (! $product) {
            throw new DomainException(
                'La presentación no tiene un producto válido.'
            );
        }

        if (
            (string) $product->base_unit_code
                !== (string) $this->base_unit_code
            || (int) $product->quantity_scale
                !== (int) $this->base_quantity_scale
        ) {
            throw new DomainException(
                'La presentación quedó desalineada de la unidad base del producto.'
            );
        }

        $entered = InventoryQuantity::positive(
            $enteredQuantity,
            InventoryQuantity::SCALE,
            'La cantidad de presentación'
        );

        InventoryQuantity::assertFitsScale(
            $entered,
            (int) $this->quantity_scale,
            'La cantidad de presentación'
        );

        $baseQuantity = InventoryQuantity::multiply(
            $entered,
            (string) $this->conversion_factor
        );

        InventoryQuantity::assertFitsScale(
            $baseQuantity,
            (int) $this->base_quantity_scale,
            'La cantidad base convertida'
        );

        return $baseQuantity;
    }
}
