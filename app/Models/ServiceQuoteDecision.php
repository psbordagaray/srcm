<?php

namespace App\Models;

use App\Enums\ServiceQuoteDecisionType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceQuoteDecision extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_quote_id',
        'service_quote_option_id',
        'decision',
        'customer_name',
        'customer_reference',
        'channel',
        'reason',
        'recorded_by_user_id',
        'decided_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La decisión del cliente es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La decisión del cliente no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'decision' => ServiceQuoteDecisionType::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class, 'service_quote_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteOption::class,
            'service_quote_option_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
