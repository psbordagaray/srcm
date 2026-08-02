<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceQuote extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'service_diagnostic_id',
        'revision',
        'currency_code',
        'valid_until',
        'terms',
        'issued_by_user_id',
        'issued_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un presupuesto emitido es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un presupuesto emitido no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'valid_until' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(
            ServiceDiagnostic::class,
            'service_diagnostic_id'
        );
    }

    public function options(): HasMany
    {
        return $this->hasMany(ServiceQuoteOption::class)
            ->orderBy('option_number');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ServiceQuoteDecision::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
