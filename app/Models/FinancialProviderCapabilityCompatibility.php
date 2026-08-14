<?php

namespace App\Models;

use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProviderCapabilityCompatibility extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'financial_provider_compatibility_id',
        'capability',
        'compatibility_status',
        'required',
        'evidence_reference',
        'notes',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una capacidad registrada de proveedor es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una capacidad registrada de proveedor no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'capability' => FinancialProviderCapability::class,
            'compatibility_status' =>
                FinancialProviderCompatibilityStatus::class,
            'required' => 'boolean',
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
