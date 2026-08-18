<?php

namespace App\Models;

use App\Domain\Purchase\PurchasePayload;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class PurchasePaymentDisbursement extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_payment_request_id',
        'purchase_payment_group_request_id',
        'origin_financial_account_id',
        'beneficiary_business_party_id',
        'channel',
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
            PurchasePaymentDisbursement $disbursement
        ): void {
            if (blank($disbursement->public_id)) {
                $disbursement->public_id =
                    (string) Str::uuid();
            }

            $disbursement->guardCreation();
        });

        static::updating(
            fn () => throw new DomainException(
                'Un desembolso confirmado es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un desembolso confirmado no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'channel' =>
                PurchasePaymentDisbursementChannel::class,
            'amount_minor' => 'integer',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function individualRequest(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentRequest::class,
            'purchase_payment_request_id'
        );
    }

    public function groupRequest(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentGroupRequest::class,
            'purchase_payment_group_request_id'
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

    public function allocations(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentDisbursementAllocation::class,
            'purchase_payment_disbursement_id'
        )->orderBy('id');
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'purchase_payment_disbursement_id'
        );
    }

    public function externalVerification(): HasOne
    {
        return $this->hasOne(
            PurchasePaymentExternalVerification::class,
            'purchase_payment_disbursement_id'
        );
    }

    private function guardCreation(): void
    {
        $individualId =
            $this->purchase_payment_request_id;
        $groupId =
            $this->purchase_payment_group_request_id;

        if (
            ($individualId === null)
                === ($groupId === null)
        ) {
            throw new DomainException(
                'El desembolso debe consumir exactamente una autorización individual o agrupada.'
            );
        }

        $origin = FinancialAccount::query()
            ->whereKey(
                $this->origin_financial_account_id
            )
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where('active', true)
            ->where(
                'currency_code',
                $this->currency_code
            )
            ->first();

        if (! $origin) {
            throw new DomainException(
                'La cuenta de origen del desembolso no está disponible.'
            );
        }

        if (! BusinessParty::query()
            ->whereKey(
                $this->beneficiary_business_party_id
            )
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->exists()
        ) {
            throw new DomainException(
                'El beneficiario del desembolso no pertenece a la organización.'
            );
        }

        $channel = $this->channel
            instanceof PurchasePaymentDisbursementChannel
            ? $this->channel
            : PurchasePaymentDisbursementChannel::tryFrom(
                (string) $this->channel
            );

        if ($channel === null) {
            throw new DomainException(
                'El canal del desembolso es inválido.'
            );
        }

        if ($channel === PurchasePaymentDisbursementChannel::Cash) {
            if (
                $origin->type
                    !== FinancialAccountType::CashBox
                || $this->cash_register_session_id
                    === null
                || $this->cash_register_id
                    === null
            ) {
                throw new DomainException(
                    'Un desembolso cash requiere caja y turno compatibles.'
                );
            }

            if (! CashRegisterSession::query()
                ->whereKey(
                    $this->cash_register_session_id
                )
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'cash_register_id',
                    $this->cash_register_id
                )
                ->where(
                    'opened_by_user_id',
                    $this->executed_by_user_id
                )
                ->where(
                    'currency_code',
                    $this->currency_code
                )
                ->where(
                    'status',
                    CashRegisterSessionStatus::Open
                )
                ->whereHas(
                    'register',
                    fn ($query) => $query
                        ->where('active', true)
                        ->where(
                            'financial_account_id',
                            $origin->id
                        )
                )
                ->exists()
            ) {
                throw new DomainException(
                    'El turno cash del desembolso no es válido.'
                );
            }
        } else {
            if (
                in_array(
                    $origin->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
                || $this->cash_register_session_id
                    !== null
                || $this->cash_register_id
                    !== null
                || blank($this->execution_reference)
            ) {
                throw new DomainException(
                    'Un desembolso non-cash requiere cuenta no monetaria-física, referencia y ausencia de turno.'
                );
            }
        }

        if ($individualId !== null) {
            $request = PurchasePaymentRequest::query()
                ->whereKey($individualId)
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'origin_financial_account_id',
                    $origin->id
                )
                ->where(
                    'beneficiary_business_party_id',
                    $this->beneficiary_business_party_id
                )
                ->where(
                    'amount_minor',
                    $this->amount_minor
                )
                ->where(
                    'currency_code',
                    $this->currency_code
                )
                ->where(
                    'status',
                    PurchasePaymentRequestStatus::Approved->value
                )
                ->first();

            if (
                ! $request
                || $request->approved_by_user_id === null
                || (int) $request->approved_by_user_id
                    === (int) $this->executed_by_user_id
            ) {
                throw new DomainException(
                    'El desembolso individual no conserva autorización segregada vigente.'
                );
            }
        } else {
            $group = PurchasePaymentGroupRequest::query()
                ->whereKey($groupId)
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'origin_financial_account_id',
                    $origin->id
                )
                ->where(
                    'beneficiary_business_party_id',
                    $this->beneficiary_business_party_id
                )
                ->where(
                    'currency_code',
                    $this->currency_code
                )
                ->where(
                    'status',
                    PurchasePaymentRequestStatus::Approved->value
                )
                ->with('items')
                ->first();

            if (
                ! $group
                || $group->approved_by_user_id === null
                || (int) $group->approved_by_user_id
                    === (int) $this->executed_by_user_id
                || (int) $group->items
                    ->sum('amount_minor')
                    !== (int) $this->amount_minor
            ) {
                throw new DomainException(
                    'El desembolso agrupado no conserva autorización segregada vigente.'
                );
            }
        }

        $this->execution_reference =
            PurchasePayload::optionalText(
                $this->execution_reference,
                'La referencia de desembolso',
                180
            );

        $this->execution_note =
            PurchasePayload::optionalText(
                $this->execution_note,
                'La nota de desembolso',
                1000
            );

        if (
            (int) $this->amount_minor <= 0
            || strlen(
                (string) $this->currency_code
            ) !== 3
            || strtoupper(
                (string) $this->currency_code
            ) !== (string) $this->currency_code
            || blank($this->idempotency_key)
            || strlen(
                (string) $this->fingerprint
            ) !== 64
            || $this->executed_by_user_id === null
            || $this->executed_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'El desembolso debe conservar importe, moneda, actor, tiempo, idempotencia y huella.'
            );
        }
    }
}
