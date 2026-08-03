<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryBaseUnit;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CatalogProduct extends Model
{
    protected $fillable = [
        'product_category_id',
        'brand_id',
        'manufacturer_id',
        'sku',
        'name',
        'description',
        'base_unit_code',
        'quantity_scale',
        'active',
    ];

    protected $attributes = [
        'base_unit_code' => 'unit',
        'quantity_scale' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(
            fn (CatalogProduct $product) =>
                $product->assertInventoryQuantityRules()
        );
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'quantity_scale' => 'integer',
        ];
    }

    public function setBaseUnitCodeAttribute(string $value): void
    {
        $this->attributes['base_unit_code'] = Str::lower(trim($value));
    }

    public function setSkuAttribute(string $value): void
    {
        $sku = Str::of($value)
            ->squish()
            ->upper()
            ->toString();

        $this->attributes['sku'] = $sku;
        $this->attributes['normalized_sku'] =
            static::normalizeIdentity($sku);
    }

    public function setNameAttribute(string $value): void
    {
        $name = Str::of($value)
            ->squish()
            ->toString();

        $this->attributes['name'] = $name;
        $this->attributes['normalized_name'] =
            static::normalizeIdentity($name);
    }

    public static function normalizeIdentity(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class, 'catalog_product_id');
    }

    public function inventoryMovementLines(): HasMany
    {
        return $this->hasMany(
            InventoryMovementLine::class,
            'catalog_product_id'
        );
    }

    public function servicePartRequirements(): HasMany
    {
        return $this->hasMany(
            ServicePartRequirement::class,
            'catalog_product_id'
        );
    }

    public function commerceSaleLines(): HasMany
    {
        return $this->hasMany(
            CommerceSaleLine::class,
            'catalog_product_id'
        );
    }

    public function baseUnit(): InventoryBaseUnit
    {
        return InventoryBaseUnit::tryFrom(
            (string) $this->base_unit_code
        ) ?? throw new DomainException(
            'La unidad base configurada no está admitida.'
        );
    }

    public function allowsFractionalQuantity(): bool
    {
        return $this->baseUnit()->allowsFractionalQuantity()
            && (int) $this->quantity_scale > 0;
    }

    public function knowledgeEntity(): BelongsTo
    {
        return $this->belongsTo(
            Entity::class,
            'knowledge_entity_id'
        );
    }

    public function knowledgeIdentifier(): BelongsTo
    {
        return $this->belongsTo(
            Identifier::class,
            'knowledge_identifier_id'
        );
    }

    private function assertInventoryQuantityRules(): void
    {
        $unit = InventoryBaseUnit::tryFrom(
            (string) $this->base_unit_code
        );
        $scale = (int) $this->quantity_scale;

        if (! $unit) {
            throw new DomainException(
                'La unidad base configurada no está admitida.'
            );
        }

        if ($scale < 0 || $scale > InventoryQuantity::SCALE) {
            throw new DomainException(
                'La precisión del producto debe estar entre 0 y '
                .InventoryQuantity::SCALE.'.'
            );
        }

        if (! $unit->allowsFractionalQuantity() && $scale !== 0) {
            throw new DomainException(
                'Un artículo contado por unidad no admite fracciones.'
            );
        }

        if (
            $this->exists
            && $this->isDirty([
                'base_unit_code',
                'quantity_scale',
            ])
            && (
                $this->inventoryMovementLines()->exists()
                || $this->servicePartRequirements()->exists()
            )
        ) {
            throw new DomainException(
                'La unidad y precisión no pueden cambiarse después del primer movimiento.'
            );
        }
    }
}
