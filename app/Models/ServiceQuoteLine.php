<?php

namespace App\Models;

use App\Enums\ServiceQuoteLineType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceQuoteLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_quote_option_id',
        'position',
        'line_type',
        'description',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una línea presupuestada es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Una línea presupuestada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'line_type' => ServiceQuoteLineType::class,
            'quantity' => 'decimal:6',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteOption::class,
            'service_quote_option_id'
        );
    }

    public function partRequirement(): HasOne
    {
        return $this->hasOne(ServicePartRequirement::class);
    }
}
