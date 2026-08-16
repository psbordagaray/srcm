<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercePostSaleRequestLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_request_id',
        'commerce_sale_line_id',
        'quantity',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Una línea de solicitud de posventa es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una línea de solicitud de posventa no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleRequest::class,
            'commerce_post_sale_request_id'
        );
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(
            CommerceSaleLine::class,
            'commerce_sale_line_id'
        );
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleReceiptLine::class,
            'commerce_post_sale_request_line_id'
        )->orderBy('id');
    }
}
