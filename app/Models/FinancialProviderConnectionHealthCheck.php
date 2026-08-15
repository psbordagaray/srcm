<?php

namespace App\Models;

use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProviderConnectionHealthCheck extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'financial_provider_connection_id',
        'financial_provider_connection_compatibility_binding_id',
        'capability',
        'health_status',
        'source_key',
        'diagnostic_code',
        'latency_ms',
        'checked_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un health check financiero es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Un health check financiero no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'capability' => FinancialProviderCapability::class,
            'health_status' =>
                FinancialProviderConnectionHealthStatus::class,
            'checked_at' => 'immutable_datetime',
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

    public function compatibilityBinding(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderConnectionCompatibilityBinding::class,
            'financial_provider_connection_compatibility_binding_id'
        );
    }
}
