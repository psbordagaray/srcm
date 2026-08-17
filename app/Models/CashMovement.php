<?php

namespace App\Models;

use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashSecurityDropReason;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashMovement extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'cash_register_session_id',
        'cash_register_id',
        'financial_account_id',
        'destination_financial_account_id',
        'cash_security_drop_request_id',
        'purchase_payment_execution_id',
        'post_sale_cash_refund_execution_id',
        'post_sale_exchange_payment_id',
        'customer_collection_id',
        'customer_advance_id',
        'supplier_advance_id',
        'commerce_payment_id',
        'direction',
        'type',
        'reason_code',
        'note',
        'amount_minor',
        'currency_code',
        'idempotency_key',
        'fingerprint',
        'recorded_by_user_id',
        'occurred_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CashMovement $movement
        ): void {
            if (blank($movement->public_id)) {
                $movement->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Un movimiento de efectivo registrado es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un movimiento de efectivo registrado no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'direction' =>
                CashMovementDirection::class,
            'type' => CashMovementType::class,
            'reason_code' =>
                CashSecurityDropReason::class,
            'amount_minor' => 'integer',
            'occurred_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            CashRegisterSession::class,
            'cash_register_session_id'
        );
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(
            CashRegister::class,
            'cash_register_id'
        );
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function destinationFinancialAccount():
        BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'destination_financial_account_id'
        );
    }

    public function securityDropRequest():
        BelongsTo
    {
        return $this->belongsTo(
            CashSecurityDropRequest::class,
            'cash_security_drop_request_id'
        );
    }

    public function commercePayment(): BelongsTo
    {
        return $this->belongsTo(
            CommercePayment::class,
            'commerce_payment_id'
        );
    }

    public function customerCollection(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCollection::class,
            'customer_collection_id'
        );
    }

    public function customerAdvance(): BelongsTo
    {
        return $this->belongsTo(
            CustomerAdvance::class,
            'customer_advance_id'
        );
    }

    public function supplierAdvance(): BelongsTo
    {
        return $this->belongsTo(
            SupplierAdvance::class,
            'supplier_advance_id'
        );
    }

    public function purchasePaymentExecution():
        BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentExecution::class,
            'purchase_payment_execution_id'
        );
    }

    public function postSaleCashRefundExecution():
        BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleCashRefundExecution::class,
            'post_sale_cash_refund_execution_id'
        );
    }

    public function postSaleExchangePayment():
        BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangePayment::class,
            'post_sale_exchange_payment_id'
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
