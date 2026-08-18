<?php

namespace App\Models;

use App\Enums\FinancialMovementStatus;
use App\Enums\PurchasePaymentExternalResolutionOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchasePaymentExternalResolution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_payment_external_verification_id',
        'reviewed_financial_external_movement_id',
        'idempotency_key',
        'fingerprint',
        'outcome',
        'observed_status',
        'amount_difference_minor',
        'fee_amount_minor',
        'withholding_amount_minor',
        'note',
        'resolved_by_user_id',
        'resolved_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentExternalResolution $resolution
        ): void {
            if (blank($resolution->public_id)) {
                $resolution->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una resolución externa de pago es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una resolución externa de pago no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'outcome' =>
                PurchasePaymentExternalResolutionOutcome::class,
            'observed_status' => FinancialMovementStatus::class,
            'amount_difference_minor' => 'integer',
            'fee_amount_minor' => 'integer',
            'withholding_amount_minor' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentExternalVerification::class,
            'purchase_payment_external_verification_id'
        );
    }

    public function reviewedMovement(): BelongsTo
    {
        return $this->belongsTo(
            FinancialExternalMovement::class,
            'reviewed_financial_external_movement_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by_user_id'
        );
    }
}
