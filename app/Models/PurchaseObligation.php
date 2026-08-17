<?php

namespace App\Models;

use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseObligation extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_order_id',
        'purchase_receipt_id',
        'supplier_id',
        'beneficiary_business_party_id',
        'kind',
        'currency_code',
        'amount_minor',
        'payment_condition',
        'due_on',
        'condition_note',
        'idempotency_key',
        'fingerprint',
        'recognized_by_user_id',
        'recognized_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseObligation $obligation): void {
            if (blank($obligation->public_id)) {
                $obligation->public_id = (string) Str::uuid();
            }

            $obligation->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una obligación económica reconocida es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una obligación económica reconocida no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'kind' => PurchaseObligationKind::class,
            'payment_condition' => PurchaseObligationCondition::class,
            'amount_minor' => 'integer',
            'due_on' => 'immutable_date',
            'recognized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrder::class,
            'purchase_order_id'
        );
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseReceipt::class,
            'purchase_receipt_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'beneficiary_business_party_id'
        );
    }

    public function recognizedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recognized_by_user_id'
        );
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentRequest::class,
            'purchase_obligation_id'
        )->orderBy('id');
    }

    public function paymentGroupItems(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentGroupRequestItem::class,
            'purchase_obligation_id'
        )->orderBy('id');
    }

    public function paymentDisbursementAllocations(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentDisbursementAllocation::class,
            'purchase_obligation_id'
        )->orderBy('id');
    }

    public function supplierCreditApplications(): HasMany
    {
        return $this->hasMany(
            SupplierCreditApplication::class,
            'purchase_obligation_id'
        )->orderBy('applied_at')
            ->orderBy('id');
    }

    public function supplierAdvanceApplications(): HasMany
    {
        return $this->hasMany(
            SupplierAdvanceApplication::class,
            'purchase_obligation_id'
        )->orderBy('applied_at')
            ->orderBy('id');
    }

    private function guardCreation(): void
    {
        $kind = $this->kind instanceof PurchaseObligationKind
            ? $this->kind
            : PurchaseObligationKind::tryFrom((string) $this->kind);
        $condition = $this->payment_condition
            instanceof PurchaseObligationCondition
            ? $this->payment_condition
            : PurchaseObligationCondition::tryFrom(
                (string) $this->payment_condition
            );

        if (! $kind || ! $condition) {
            throw new DomainException(
                'La clase o condición de la obligación es inválida.'
            );
        }

        $receipt = PurchaseReceipt::query()
            ->whereKey($this->purchase_receipt_id)
            ->where('organization_id', $this->organization_id)
            ->where('purchase_order_id', $this->purchase_order_id)
            ->where('supplier_id', $this->supplier_id)
            ->first();

        $order = PurchaseOrder::query()
            ->whereKey($this->purchase_order_id)
            ->where('organization_id', $this->organization_id)
            ->where('supplier_id', $this->supplier_id)
            ->first();

        if (! $receipt || ! $order) {
            throw new DomainException(
                'La obligación no corresponde a una recepción de compra válida.'
            );
        }

        $expectedAmount = match ($kind) {
            PurchaseObligationKind::Merchandise =>
                (int) $receipt->merchandise_total_minor,
            PurchaseObligationKind::Logistics =>
                (int) $receipt->logistics_cost_minor,
        };

        if (
            $expectedAmount <= 0
            || (int) $this->amount_minor !== $expectedAmount
            || (string) $this->currency_code
                !== (string) $order->currency_code
        ) {
            throw new DomainException(
                'La obligación debe conservar el importe y moneda exactos de su recepción.'
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

        $dueOn = $this->due_on?->format('Y-m-d');

        if (
            $condition === PurchaseObligationCondition::DueDate
            && $dueOn === null
        ) {
            throw new DomainException(
                'La obligación con vencimiento requiere una fecha.'
            );
        }

        if (
            $condition !== PurchaseObligationCondition::DueDate
            && $dueOn !== null
        ) {
            throw new DomainException(
                'Sólo la condición con vencimiento admite una fecha.'
            );
        }

        $this->condition_note = PurchasePayload::optionalText(
            $this->condition_note,
            'El detalle de condición',
            1000
        );

        if (
            $condition === PurchaseObligationCondition::Other
            && $this->condition_note === null
        ) {
            throw new DomainException(
                'Otra condición de pago requiere detalle.'
            );
        }

        if (
            blank($this->idempotency_key)
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->recognized_by_user_id === null
            || $this->recognized_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La obligación debe conservar idempotencia, huella, responsable y tiempo.'
            );
        }
    }
}
