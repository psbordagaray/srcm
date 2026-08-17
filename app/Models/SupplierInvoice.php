<?php

namespace App\Models;

use App\Domain\Purchase\PurchaseMoney;
use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupplierInvoice extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_order_id',
        'supplier_id',
        'document_number',
        'normalized_document_number',
        'issued_on',
        'due_on',
        'currency_code',
        'merchandise_total_minor',
        'logistics_amount_minor',
        'total_minor',
        'idempotency_key',
        'fingerprint',
        'recorded_by_user_id',
        'recorded_at',
        'notes',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            SupplierInvoice $invoice
        ): void {
            if (blank($invoice->public_id)) {
                $invoice->public_id =
                    (string) Str::uuid();
            }

            $invoice->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Un documento de proveedor confirmado es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Un documento de proveedor confirmado no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'merchandise_total_minor' => 'integer',
            'logistics_amount_minor' => 'integer',
            'total_minor' => 'integer',
            'recorded_at' => 'immutable_datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(
            SupplierInvoiceLine::class,
            'supplier_invoice_id'
        )->orderBy('sequence');
    }

    private function guardCreation(): void
    {
        $order = PurchaseOrder::query()
            ->whereKey($this->purchase_order_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where('supplier_id', $this->supplier_id)
            ->first();

        if (
            ! $order
            || in_array(
                $order->status,
                [
                    PurchaseOrderStatus::Draft,
                    PurchaseOrderStatus::Cancelled,
                ],
                true
            )
        ) {
            throw new DomainException(
                'El documento no corresponde a una orden emitida válida.'
            );
        }

        if (
            (string) $this->currency_code
                !== (string) $order->currency_code
        ) {
            throw new DomainException(
                'La moneda documentada debe coincidir con la orden.'
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
                'La identidad normalizada del documento no es válida.'
            );
        }

        $merchandise = PurchaseMoney::nonNegative(
            (int) $this->merchandise_total_minor
        );
        $logistics = PurchaseMoney::nonNegative(
            (int) $this->logistics_amount_minor
        );
        $expected = PurchaseMoney::add(
            $merchandise,
            $logistics
        );

        if (
            $expected <= 0
            || (int) $this->total_minor
                !== $expected
        ) {
            throw new DomainException(
                'El total del documento no coincide con sus componentes.'
            );
        }

        $issuedOn = CarbonImmutable::parse(
            (string) $this->issued_on,
            'UTC'
        )->startOfDay();

        if ($this->due_on !== null) {
            $dueOn = CarbonImmutable::parse(
                (string) $this->due_on,
                'UTC'
            )->startOfDay();

            if ($dueOn->lessThan($issuedOn)) {
                throw new DomainException(
                    'El vencimiento documentado no puede preceder a su emisión.'
                );
            }
        }

        if (
            blank($this->idempotency_key)
            || strlen((string) $this->fingerprint)
                !== 64
        ) {
            throw new DomainException(
                'El documento requiere idempotencia y huella válidas.'
            );
        }
    }
}
