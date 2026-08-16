<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePostSaleExchangeSelectionLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_exchange_selection_id',
        'sequence',
        'catalog_product_id',
        'organization_product_price_id',
        'quantity',
        'unit_price_minor',
        'line_amount_minor',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Una línea de reemplazo confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una línea de reemplazo confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'quantity' => 'decimal:6',
            'unit_price_minor' => 'integer',
            'line_amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeSelection::class,
            'commerce_post_sale_exchange_selection_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(
            OrganizationProductPrice::class,
            'organization_product_price_id'
        );
    }
}
