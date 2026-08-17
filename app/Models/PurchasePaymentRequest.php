<?php

namespace App\Models;

use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class PurchasePaymentRequest extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_obligation_id',
        'origin_financial_account_id',
        'beneficiary_business_party_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'resolved_by_user_id',
        'amount_minor',
        'currency_code',
        'status',
        'request_note',
        'request_idempotency_key',
        'fingerprint',
        'approval_idempotency_key',
        'approval_fingerprint',
        'approval_note',
        'resolution_idempotency_key',
        'resolution_note',
        'requested_at',
        'approved_at',
        'resolved_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentRequest $request
        ): void {
            if (blank($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }

            $request->guardCreation();
        });

        static::updating(function (
            PurchasePaymentRequest $request
        ): void {
            foreach ([
                'organization_id',
                'public_id',
                'purchase_obligation_id',
                'origin_financial_account_id',
                'beneficiary_business_party_id',
                'requested_by_user_id',
                'amount_minor',
                'currency_code',
                'request_note',
                'request_idempotency_key',
                'fingerprint',
                'requested_at',
                'created_at',
            ] as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new DomainException(
                        'Los hechos autorizables de una solicitud de pago son inmutables.'
                    );
                }
            }

            $original = PurchasePaymentRequestStatus::from(
                (string) $request->getRawOriginal('status')
            );

            if ($original->isTerminal()) {
                throw new DomainException(
                    'Una solicitud de pago resuelta es inmutable.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una solicitud de autorización de pago no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => PurchasePaymentRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by_user_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by_user_id'
        );
    }

    public function execution(): HasOne
    {
        return $this->hasOne(
            PurchasePaymentExecution::class,
            'purchase_payment_request_id'
        );
    }

    public function disbursement(): HasOne
    {
        return $this->hasOne(
            PurchasePaymentDisbursement::class,
            'purchase_payment_request_id'
        );
    }

    public function disbursementAllocation(): HasOne
    {
        return $this->hasOne(
            PurchasePaymentDisbursementAllocation::class,
            'purchase_payment_request_id'
        );
    }

    private function guardCreation(): void
    {
        $status = $this->status
            instanceof PurchasePaymentRequestStatus
            ? $this->status
            : PurchasePaymentRequestStatus::tryFrom(
                (string) $this->status
            );

        if ($status !== PurchasePaymentRequestStatus::Pending) {
            throw new DomainException(
                'Una solicitud de pago debe nacer pendiente.'
            );
        }

        $obligation = PurchaseObligation::query()
            ->whereKey($this->purchase_obligation_id)
            ->where('organization_id', $this->organization_id)
            ->where(
                'beneficiary_business_party_id',
                $this->beneficiary_business_party_id
            )
            ->where('currency_code', $this->currency_code)
            ->first();

        $executedMinor = $obligation
            ? (int) PurchasePaymentExecution::query()
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->sum('amount_minor')
                + (int) PurchasePaymentDisbursementAllocation::query()
                    ->where(
                        'organization_id',
                        $this->organization_id
                    )
                    ->where(
                        'purchase_obligation_id',
                        $obligation->id
                    )
                    ->sum('amount_minor')
            : 0;
        $remainingMinor = $obligation
            ? max(
                0,
                (int) $obligation->amount_minor
                    - $executedMinor
            )
            : 0;

        if (
            ! $obligation
            || (int) $this->amount_minor <= 0
            || (int) $this->amount_minor
                > $remainingMinor
        ) {
            throw new DomainException(
                'La solicitud no conserva una obligación y saldo ejecutable suficientes.'
            );
        }

        if (! FinancialAccount::query()
            ->whereKey($this->origin_financial_account_id)
            ->where('organization_id', $this->organization_id)
            ->where('active', true)
            ->where('currency_code', $this->currency_code)
            ->exists()
        ) {
            throw new DomainException(
                'La cuenta de origen propuesta no está disponible.'
            );
        }

        if (! BusinessParty::query()
            ->whereKey($this->beneficiary_business_party_id)
            ->where('organization_id', $this->organization_id)
            ->exists()
        ) {
            throw new DomainException(
                'El beneficiario no pertenece a la organización.'
            );
        }

        if (
            $this->approved_by_user_id !== null
            || $this->resolved_by_user_id !== null
            || $this->approval_idempotency_key !== null
            || $this->approval_fingerprint !== null
            || $this->approval_note !== null
            || $this->resolution_idempotency_key !== null
            || $this->resolution_note !== null
            || $this->approved_at !== null
            || $this->resolved_at !== null
        ) {
            throw new DomainException(
                'Una solicitud nueva no puede nacer aprobada o resuelta.'
            );
        }

        $this->request_note = PurchasePayload::optionalText(
            $this->request_note,
            'La nota de solicitud',
            1000
        );

        if (
            blank($this->request_idempotency_key)
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->requested_by_user_id === null
            || $this->requested_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La solicitud debe conservar idempotencia, huella, solicitante y tiempo.'
            );
        }
    }
}
