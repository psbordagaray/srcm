<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseMoney;
use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'sequence',
        'supplier_id',
        'catalog_product_id',
        'supplier_offer_id',
        'supplier_code',
        'description',
        'base_unit_code',
        'quantity_scale',
        'ordered_quantity',
        'unit_cost_minor',
        'subtotal_minor',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderLine $line): void {
            $order = $line->guardDraftOrder();
            $line->guardProduct();
            $line->guardOffer($order);
            $line->normalizeMoneyAndQuantity();
        });

        static::deleting(function (PurchaseOrderLine $line): void {
            $line->guardDraftOrder();
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_scale' => 'integer',
            'ordered_quantity' => 'decimal:6',
            'unit_cost_minor' => 'integer',
            'subtotal_minor' => 'integer',
        ];
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(
            SupplierOffer::class,
            'supplier_offer_id'
        );
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(
            PurchaseReceiptLine::class,
            'purchase_order_line_id'
        );
    }

    private function guardDraftOrder(): PurchaseOrder
    {
        $order = PurchaseOrder::query()
            ->whereKey($this->purchase_order_id)
            ->where('organization_id', $this->organization_id)
            ->first();

        if (! $order) {
            throw new DomainException(
                'La línea no pertenece a una orden de la organización.'
            );
        }

        if ($order->status !== PurchaseOrderStatus::Draft) {
            throw new DomainException(
                'Las líneas de una orden emitida son inmutables.'
            );
        }

        if ((int) $this->supplier_id !== (int) $order->supplier_id) {
            throw new DomainException(
                'El proveedor de la línea no coincide con la orden.'
            );
        }

        return $order;
    }

    private function guardProduct(): void
    {
        $product = CatalogProduct::query()
            ->whereKey($this->catalog_product_id)
            ->where('active', true)
            ->first();

        if (! $product) {
            throw new DomainException(
                'La línea referencia un producto inexistente o inactivo.'
            );
        }

        if (
            $product->base_unit_code !== $this->base_unit_code
            || (int) $product->quantity_scale
                !== (int) $this->quantity_scale
        ) {
            throw new DomainException(
                'La unidad o precisión congelada no coincide con el producto.'
            );
        }
    }

    private function guardOffer(PurchaseOrder $order): void
    {
        if ($this->supplier_offer_id === null) {
            return;
        }

        $matches = SupplierOffer::query()
            ->whereKey($this->supplier_offer_id)
            ->where('organization_id', $this->organization_id)
            ->where('supplier_id', $order->supplier_id)
            ->where('catalog_product_id', $this->catalog_product_id)
            ->where('active', true)
            ->exists();

        if (! $matches) {
            throw new DomainException(
                'La oferta no está activa o no coincide con proveedor y producto.'
            );
        }
    }

    private function normalizeMoneyAndQuantity(): void
    {
        $attributes = $this->getAttributes();
        $quantity = InventoryQuantity::positive(
            $attributes['ordered_quantity'] ?? null
        );

        InventoryQuantity::assertFitsScale(
            $quantity,
            (int) $this->quantity_scale,
            'La cantidad ordenada'
        );

        $this->ordered_quantity = $quantity;
        $this->unit_cost_minor = PurchaseMoney::nonNegative(
            (int) $this->unit_cost_minor,
            'El costo unitario acordado'
        );
        $this->subtotal_minor = PurchaseMoney::subtotal(
            $quantity,
            (int) $this->unit_cost_minor
        );
        $this->supplier_code = PurchasePayload::optionalText(
            $this->supplier_code,
            'El código del proveedor',
            255
        );
        $this->description = PurchasePayload::requiredText(
            $this->description,
            'La descripción comercial',
            1000
        );
    }
}
