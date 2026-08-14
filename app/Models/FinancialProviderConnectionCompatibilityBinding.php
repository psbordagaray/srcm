<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProviderConnectionCompatibilityBinding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'financial_provider_connection_id',
        'financial_provider_compatibility_id',
        'previous_binding_id',
        'bound_by_user_id',
        'bound_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La vinculación de compatibilidad de proveedor es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La vinculación de compatibilidad de proveedor no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'bound_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderConnection::class,
            'financial_provider_connection_id'
        );
    }

    public function compatibility(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderCompatibility::class,
            'financial_provider_compatibility_id'
        );
    }

    public function previousBinding(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'previous_binding_id'
        );
    }

    public function boundBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'bound_by_user_id'
        );
    }
}
