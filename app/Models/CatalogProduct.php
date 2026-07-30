<?php

namespace App\Models;

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
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
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
}
