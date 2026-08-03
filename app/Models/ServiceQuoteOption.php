<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceQuoteOption extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_quote_id',
        'option_number',
        'label',
        'description',
        'recommended',
        'total_minor',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una alternativa presupuestada es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Una alternativa presupuestada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'recommended' => 'boolean',
            'total_minor' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class, 'service_quote_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceQuoteLine::class)
            ->orderBy('position');
    }

    public function commerceSale(): HasOne
    {
        return $this->hasOne(CommerceSale::class);
    }
}
