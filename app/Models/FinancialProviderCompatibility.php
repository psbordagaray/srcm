<?php

namespace App\Models;

use App\Enums\FinancialProviderCompatibilityStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialProviderCompatibility extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'registry_key',
        'provider_key',
        'provider_label',
        'provider_contract_version',
        'provider_contract_reference',
        'adapter_class',
        'adapter_contract_version',
        'compatibility_status',
        'migration_required',
        'srcm_version',
        'verified_at',
        'notes',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una evaluación de compatibilidad de proveedor es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una evaluación de compatibilidad de proveedor no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'compatibility_status' =>
                FinancialProviderCompatibilityStatus::class,
            'migration_required' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(
            FinancialProviderCapabilityCompatibility::class,
            'financial_provider_compatibility_id'
        );
    }

    public function connectionBindings(): HasMany
    {
        return $this->hasMany(
            FinancialProviderConnectionCompatibilityBinding::class,
            'financial_provider_compatibility_id'
        );
    }

    public function retirement(): HasOne
    {
        return $this->hasOne(
            FinancialProviderCompatibilityRetirement::class,
            'financial_provider_compatibility_id'
        );
    }
}
