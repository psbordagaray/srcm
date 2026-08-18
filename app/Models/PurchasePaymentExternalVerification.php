<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchasePaymentExternalVerification extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_payment_disbursement_id',
        'financial_external_movement_id',
        'idempotency_key',
        'fingerprint',
        'reference_match_kind',
        'amount_difference_minor',
        'note',
        'verified_by_user_id',
        'verified_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentExternalVerification $verification
        ): void {
            if (blank($verification->public_id)) {
                $verification->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una verificación externa de desembolso es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una verificación externa de desembolso no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_difference_minor' => 'integer',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentDisbursement::class,
            'purchase_payment_disbursement_id'
        );
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(
            FinancialExternalMovement::class,
            'financial_external_movement_id'
        );
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by_user_id'
        );
    }
}
