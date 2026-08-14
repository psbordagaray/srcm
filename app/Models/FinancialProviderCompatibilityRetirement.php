<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProviderCompatibilityRetirement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'financial_provider_compatibility_id',
        'reason',
        'srcm_version',
        'retired_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El retiro de compatibilidad de proveedor es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'El retiro de compatibilidad de proveedor no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function compatibility(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderCompatibility::class,
            'financial_provider_compatibility_id'
        );
    }
}
