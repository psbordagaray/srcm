<?php

namespace App\Models;

use App\Enums\ServiceCancellationFinancialOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceCancellationResolution extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_cancellation_request_id',
        'financial_outcome',
        'currency_code',
        'customer_charge_minor',
        'customer_acceptance_reference',
        'work_disposition',
        'parts_disposition',
        'financial_disposition',
        'return_condition_notes',
        'accessories_snapshot',
        'notes',
        'resolved_by_user_id',
        'resolved_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La resolución de cancelación es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La resolución de cancelación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'financial_outcome' =>
                ServiceCancellationFinancialOutcome::class,
            'customer_charge_minor' => 'integer',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCancellationRequest::class,
            'service_cancellation_request_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function returnRecord(): HasOne
    {
        return $this->hasOne(ServiceCancellationReturn::class);
    }
}
