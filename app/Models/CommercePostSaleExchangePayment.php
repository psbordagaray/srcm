<?php

namespace App\Models;

use App\Enums\CommercePaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommercePostSaleExchangePayment extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_exchange_execution_id',
        'sequence',
        'financial_account_id',
        'cash_register_session_id',
        'cash_register_id',
        'method',
        'amount_minor',
        'tendered_amount_minor',
        'change_amount_minor',
        'reference',
        'card_brand',
        'card_network',
        'card_last4',
        'installments',
        'processor',
        'external_operation_id',
        'authorization_code',
        'provider_status',
        'notes',
        'received_by_user_id',
        'paid_at',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Un cobro de diferencia de cambio es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un cobro de diferencia de cambio no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'method' => CommercePaymentMethod::class,
            'amount_minor' => 'integer',
            'tendered_amount_minor' => 'integer',
            'change_amount_minor' => 'integer',
            'installments' => 'integer',
            'paid_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeExecution::class,
            'commerce_post_sale_exchange_execution_id'
        );
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by_user_id'
        );
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'post_sale_exchange_payment_id'
        );
    }
}
