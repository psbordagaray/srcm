<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SupplierAdvance extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_advance_request_id',
        'supplier_id',
        'origin_financial_account_id',
        'cash_register_session_id',
        'cash_register_id',
        'executed_by_user_id',
        'channel',
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
            SupplierAdvance $advance
        ): void {
            if (blank($advance->public_id)) {
                $advance->public_id =
                    (string) Str::uuid();
            }

            if (
                ! in_array(
                    $advance->channel,
                    ['cash', 'noncash'],
                    true
                )
                || (int) $advance->amount_minor <= 0
                || strlen(
                    (string) $advance->currency_code
                ) !== 3
                || blank($advance->idempotency_key)
                || strlen(
                    (string) $advance->fingerprint
                ) !== 64
                || $advance->executed_by_user_id
                    === null
                || $advance->executed_at === null
                || $advance->created_at === null
            ) {
                throw new DomainException(
                    'El anticipo ejecutado no conserva canal, importe, moneda, idempotencia, actor o tiempo válidos.'
                );
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Un anticipo ejecutado de proveedor es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un anticipo ejecutado de proveedor no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'executed_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            SupplierAdvanceRequest::class,
            'supplier_advance_request_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class
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
            'supplier_advance_id'
        );
    }
}
