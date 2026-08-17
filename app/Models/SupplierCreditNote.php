<?php

namespace App\Models;

use App\Domain\Purchase\PurchasePayload;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierCreditNote extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_invoice_id',
        'purchase_order_id',
        'supplier_id',
        'document_number',
        'normalized_document_number',
        'issued_on',
        'currency_code',
        'amount_minor',
        'reason',
        'notes',
        'idempotency_key',
        'fingerprint',
        'recorded_by_user_id',
        'recorded_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            SupplierCreditNote $creditNote
        ): void {
            if (blank($creditNote->public_id)) {
                $creditNote->public_id =
                    (string) Str::uuid();
            }

            $creditNote->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una nota de crédito de proveedor confirmada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una nota de crédito de proveedor confirmada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'recorded_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            SupplierInvoice::class,
            'supplier_invoice_id'
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrder::class,
            'purchase_order_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        $invoice = SupplierInvoice::query()
            ->whereKey($this->supplier_invoice_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'purchase_order_id',
                $this->purchase_order_id
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

        if (! $invoice) {
            throw new DomainException(
                'La nota de crédito no corresponde a una factura válida.'
            );
        }

        $document = PurchasePayload::documentReference(
            $this->document_number
        );

        if (
            $document['normalized'] === null
            || $document['normalized']
                !== $this->normalized_document_number
        ) {
            throw new DomainException(
                'La identidad normalizada de la nota de crédito no es válida.'
            );
        }

        $reason = PurchasePayload::requiredText(
            $this->reason,
            'El motivo de la nota de crédito',
            1000
        );

        if ($reason !== $this->reason) {
            throw new DomainException(
                'El motivo de la nota de crédito debe conservar su forma normalizada.'
            );
        }

        $issuedOn = CarbonImmutable::parse(
            (string) $this->issued_on,
            'UTC'
        )->startOfDay();

        if (
            $issuedOn->lessThan(
                $invoice->issued_on->startOfDay()
            )
        ) {
            throw new DomainException(
                'La nota de crédito no puede preceder a la factura.'
            );
        }

        $creditedMinor = (int) self::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'supplier_invoice_id',
                $invoice->id
            )
            ->sum('amount_minor');

        if (
            (int) $this->amount_minor <= 0
            || $creditedMinor
                + (int) $this->amount_minor
                > (int) $invoice->total_minor
        ) {
            throw new DomainException(
                'El importe acreditado supera el documento vinculado.'
            );
        }

        $this->notes = PurchasePayload::optionalText(
            $this->notes,
            'Las notas de la nota de crédito',
            4000
        );

        if (
            blank($this->idempotency_key)
            || strlen((string) $this->fingerprint)
                !== 64
            || $this->recorded_by_user_id === null
            || $this->recorded_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La nota de crédito requiere idempotencia, huella, actor y tiempo.'
            );
        }
    }
}
