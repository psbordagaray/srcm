<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class PurchasePaymentExecution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_payment_request_id',
        'purchase_obligation_id',
        'origin_financial_account_id',
        'beneficiary_business_party_id',
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
        static::creating(function (PurchasePaymentExecution $execution): void {
            if (blank($execution->public_id)) {
                $execution->public_id = (string) Str::uuid();
            }

            $execution->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una ejecución de pago confirmada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una ejecución de pago confirmada no puede eliminarse.'
        ));
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentRequest::class,
            'purchase_payment_request_id'
        );
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseObligation::class,
            'purchase_obligation_id'
        );
    }

    public function originFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'origin_financial_account_id'
        );
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'beneficiary_business_party_id'
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
            'purchase_payment_execution_id'
        );
    }

    private function guardCreation(): void
    {
        $request = PurchasePaymentRequest::query()
            ->whereKey($this->purchase_payment_request_id)
            ->where('organization_id', $this->organization_id)
            ->where('purchase_obligation_id', $this->purchase_obligation_id)
            ->where(
                'origin_financial_account_id',
                $this->origin_financial_account_id
            )
            ->where(
                'beneficiary_business_party_id',
                $this->beneficiary_business_party_id
            )
            ->where('amount_minor', $this->amount_minor)
            ->where('currency_code', $this->currency_code)
            ->where('status', 'approved')
            ->first();

        if (
            ! $request
            || $request->approved_by_user_id === null
            || (int) $request->approved_by_user_id
                === (int) $this->executed_by_user_id
        ) {
            throw new DomainException(
                'La ejecución no conserva una autorización vigente y segregada.'
            );
        }

        if (
            (int) $this->amount_minor <= 0
            || blank($this->idempotency_key)
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->executed_by_user_id === null
            || $this->executed_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La ejecución debe conservar importe, actor, tiempo, idempotencia y huella.'
            );
        }
    }
}
