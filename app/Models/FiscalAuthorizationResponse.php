<?php

namespace App\Models;

use App\Enums\FiscalAuthorizationOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalAuthorizationResponse extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'fiscal_authorization_attempt_id',
        'outcome',
        'result_code',
        'authorization_code',
        'authorization_code_expires_on',
        'provider_evidence',
        'received_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () =>
                throw new DomainException(
                    'Una respuesta fiscal es inmutable.'
                )
        );

        static::deleting(
            fn () =>
                throw new DomainException(
                    'Una respuesta fiscal no puede eliminarse.'
                )
        );
    }

    protected function casts(): array
    {
        return [
            'outcome' =>
                FiscalAuthorizationOutcome::class,
            'authorization_code_expires_on' =>
                'immutable_date:Y-m-d',
            'provider_evidence' =>
                'array',
            'received_at' =>
                'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(
            FiscalAuthorizationAttempt::class,
            'fiscal_authorization_attempt_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by_user_id'
        );
    }
}
