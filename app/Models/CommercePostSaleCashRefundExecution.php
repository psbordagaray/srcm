<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CommercePostSaleCashRefundExecution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_resolution_id',
        'original_commerce_payment_id',
        'origin_financial_account_id',
        'cash_register_session_id',
        'cash_register_id',
        'executed_by_user_id',
        'amount_minor',
        'currency_code',
        'execution_reference',
        'execution_note',
        'idempotency_key',
        'fingerprint',
        'executed_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleCashRefundExecution $execution
        ): void {
            if (blank($execution->public_id)) {
                $execution->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Un reembolso efectivo ejecutado es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un reembolso efectivo ejecutado no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleResolution::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(
            CommercePayment::class,
            'original_commerce_payment_id'
        );
    }

    public function originFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'origin_financial_account_id'
        );
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(
            CashRegisterSession::class,
            'cash_register_session_id'
        );
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(
            CashRegister::class,
            'cash_register_id'
        );
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'executed_by_user_id'
        );
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'post_sale_cash_refund_execution_id'
        );
    }
}
