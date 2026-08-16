<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePostSaleResolutionLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_resolution_id',
        'commerce_post_sale_receipt_line_id',
        'quantity',
        'baseline_amount_minor',
        'recognized_amount_minor',
        'adjustment_reason',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Una línea de resolución de posventa es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una línea de resolución de posventa no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'baseline_amount_minor' => 'integer',
            'recognized_amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleResolution::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleReceiptLine::class,
            'commerce_post_sale_receipt_line_id'
        );
    }
}
