<?php

namespace App\Models;

use App\Enums\SupplierAdvanceDecisionType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierAdvanceDecision extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_advance_request_id',
        'decision',
        'decision_note',
        'decided_by_user_id',
        'idempotency_key',
        'fingerprint',
        'decided_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            SupplierAdvanceDecision $decision
        ): void {
            if (blank($decision->public_id)) {
                $decision->public_id =
                    (string) Str::uuid();
            }

            if (
                blank($decision->idempotency_key)
                || strlen(
                    (string) $decision->fingerprint
                ) !== 64
                || $decision->decided_by_user_id
                    === null
                || $decision->decided_at === null
                || $decision->created_at === null
            ) {
                throw new DomainException(
                    'La decisión de anticipo requiere idempotencia, huella, actor y tiempo.'
                );
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una decisión de anticipo es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una decisión de anticipo no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'decision' =>
                SupplierAdvanceDecisionType::class,
            'decided_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            SupplierAdvanceRequest::class,
            'supplier_advance_request_id'
        );
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by_user_id'
        );
    }
}
