<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseMoney;
use App\Domain\Purchase\PurchasePayload;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceLine extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'supplier_invoice_id',
        'purchase_order_id',
        'purchase_order_line_id',
        'sequence',
        'catalog_product_id',
        'supplier_code',
        'description',
        'quantity',
        'unit_cost_minor',
        'subtotal_minor',
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (SupplierInvoiceLine $line) =>
                $line->guardCreation()
        );

        static::updating(fn () => throw new DomainException(
            'Una línea documentada confirmada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una línea documentada confirmada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'quantity' => 'decimal:6',
            'unit_cost_minor' => 'integer',
            'subtotal_minor' => 'integer',
        ];
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

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrderLine::class,
            'purchase_order_line_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
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
            ->first();

        if (! $invoice) {
            throw new DomainException(
                'La línea no pertenece al documento indicado.'
            );
        }

        $orderLine = null;

        if ($this->purchase_order_line_id !== null) {
            $orderLine = PurchaseOrderLine::query()
                ->whereKey(
                    $this->purchase_order_line_id
                )
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_order_id',
                    $this->purchase_order_id
                )
                ->first();

            if (
                ! $orderLine
                || (int) $this->catalog_product_id
                    !== (int) $orderLine
                        ->catalog_product_id
            ) {
                throw new DomainException(
                    'La línea documentada no coincide con la línea de orden declarada.'
                );
            }
        } elseif ($this->catalog_product_id !== null) {
            throw new DomainException(
                'Una línea no vinculada a la orden no puede inventar un producto catalogado.'
            );
        }

        $quantity = InventoryQuantity::positive(
            $this->quantity
        );

        InventoryQuantity::assertFitsScale(
            $quantity,
            $orderLine
                ? (int) $orderLine->quantity_scale
                : 6,
            'La cantidad documentada'
        );

        $description = PurchasePayload::requiredText(
            $this->description,
            'La descripción documentada',
            255
        );

        if ($description !== $this->description) {
            throw new DomainException(
                'La descripción documentada debe conservar su forma normalizada.'
            );
        }

        $expectedSubtotal = PurchaseMoney::subtotal(
            $quantity,
            (int) $this->unit_cost_minor
        );

        if (
            (int) $this->subtotal_minor
                !== $expectedSubtotal
        ) {
            throw new DomainException(
                'El subtotal documentado no coincide con cantidad y costo.'
            );
        }
    }
}
