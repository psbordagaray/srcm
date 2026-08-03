<?php

namespace App\Models;

use App\Enums\CommerceSaleLineType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceSaleLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'commerce_sale_id',
        'position',
        'line_type',
        'description',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'service_quote_line_id',
        'catalog_product_id',
        'inventory_movement_line_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una línea comercial es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Una línea comercial no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'line_type' => CommerceSaleLineType::class,
            'quantity' => 'decimal:6',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(CommerceSale::class, 'commerce_sale_id');
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteLine::class,
            'service_quote_line_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }

    public function inventoryMovementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }
}
