<?php

namespace App\Models;

use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierCreditApplication extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_credit_note_id',
        'purchase_obligation_id',
        'supplier_id',
        'beneficiary_business_party_id',
        'currency_code',
        'amount_minor',
        'application_note',
        'idempotency_key',
        'fingerprint',
        'applied_by_user_id',
        'applied_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            SupplierCreditApplication $application
        ): void {
            if (blank($application->public_id)) {
                $application->public_id =
                    (string) Str::uuid();
            }

            $application->guardCreation();
        });

        static::updating(
            fn () => throw new DomainException(
                'Una aplicación de crédito de proveedor es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una aplicación de crédito de proveedor no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(
            SupplierCreditNote::class,
            'supplier_credit_note_id'
        );
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseObligation::class,
            'purchase_obligation_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class
        );
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'beneficiary_business_party_id'
        );
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'applied_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        $creditNote = SupplierCreditNote::query()
            ->whereKey(
                $this->supplier_credit_note_id
            )
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'supplier_id',
                $this->supplier_id
            )
            ->where(
                'currency_code',
                $this->currency_code
            )
            ->first();

        $obligation = PurchaseObligation::query()
            ->whereKey(
                $this->purchase_obligation_id
            )
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'supplier_id',
                $this->supplier_id
            )
            ->where(
                'beneficiary_business_party_id',
                $this->beneficiary_business_party_id
            )
            ->where(
                'currency_code',
                $this->currency_code
            )
            ->first();

        $supplier = Supplier::query()
            ->whereKey($this->supplier_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->first();

        if (
            ! $creditNote
            || ! $obligation
            || ! $supplier
            || (int) $supplier->business_party_id
                !== (int) $this
                    ->beneficiary_business_party_id
        ) {
            throw new DomainException(
                'La aplicación no conserva proveedor, beneficiario, moneda y organización.'
            );
        }

        if (
            PurchasePaymentRequest::query()
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->whereIn('status', [
                    PurchasePaymentRequestStatus::Pending->value,
                    PurchasePaymentRequestStatus::Approved->value,
                ])
                ->exists()
        ) {
            throw new DomainException(
                'No puede aplicarse crédito con una solicitud de pago activa.'
            );
        }

        $sourceApplied = (int) self::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'supplier_credit_note_id',
                $creditNote->id
            )
            ->sum('amount_minor');

        $obligationApplied = (int) self::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'purchase_obligation_id',
                $obligation->id
            )
            ->sum('amount_minor');

        $executed = (int) PurchasePaymentExecution::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'purchase_obligation_id',
                $obligation->id
            )
            ->sum('amount_minor');

        if (
            (int) $this->amount_minor <= 0
            || $sourceApplied
                + (int) $this->amount_minor
                > (int) $creditNote->amount_minor
            || $obligationApplied
                + $executed
                + (int) $this->amount_minor
                > (int) $obligation->amount_minor
        ) {
            throw new DomainException(
                'La aplicación excede el crédito o el saldo de obligación disponible.'
            );
        }

        $this->application_note =
            PurchasePayload::optionalText(
                $this->application_note,
                'La nota de aplicación',
                1000
            );

        if (
            blank($this->idempotency_key)
            || strlen(
                (string) $this->fingerprint
            ) !== 64
            || $this->applied_by_user_id === null
            || $this->applied_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La aplicación requiere idempotencia, huella, actor y tiempo.'
            );
        }
    }
}
