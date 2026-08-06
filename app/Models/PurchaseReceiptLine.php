<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseMoney;
use App\Enums\InventoryCondition;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'purchase_receipt_id',
        'purchase_order_id',
        'purchase_order_line_id',
        'inventory_movement_id',
        'inventory_movement_line_id',
        'sequence',
        'catalog_product_id',
        'inventory_location_id',
        'condition',
        'received_quantity',
        'actual_unit_cost_minor',
        'subtotal_minor',
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (PurchaseReceiptLine $line) =>
                $line->guardCreation()
        );

        static::updating(function (): never {
            throw new DomainException(
                'Una línea de recepción confirmada es inmutable.'
            );
        });

        static::deleting(function (): never {
            throw new DomainException(
                'Una línea de recepción confirmada no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'received_quantity' => 'decimal:6',
            'actual_unit_cost_minor' => 'integer',
            'subtotal_minor' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseReceipt::class,
            'purchase_receipt_id'
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

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function inventoryMovementLine(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovementLine::class,
            'inventory_movement_line_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'inventory_location_id'
        );
    }

    private function guardCreation(): void
    {
        $receipt = PurchaseReceipt::query()
            ->whereKey($this->purchase_receipt_id)
            ->where('organization_id', $this->organization_id)
            ->where('purchase_order_id', $this->purchase_order_id)
            ->where('inventory_movement_id', $this->inventory_movement_id)
            ->first();

        if (! $receipt) {
            throw new DomainException(
                'La línea no corresponde a la recepción y movimiento indicados.'
            );
        }

        $orderLine = PurchaseOrderLine::query()
            ->whereKey($this->purchase_order_line_id)
            ->where('organization_id', $this->organization_id)
            ->where('purchase_order_id', $this->purchase_order_id)
            ->where('catalog_product_id', $this->catalog_product_id)
            ->first();

        if (! $orderLine) {
            throw new DomainException(
                'La línea recibida no corresponde a una línea de la orden.'
            );
        }

        $movementLine = InventoryMovementLine::query()
            ->whereKey($this->inventory_movement_line_id)
            ->where('organization_id', $this->organization_id)
            ->where('inventory_movement_id', $this->inventory_movement_id)
            ->where('catalog_product_id', $this->catalog_product_id)
            ->where(
                'destination_location_id',
                $this->inventory_location_id
            )
            ->first();

        if (! $movementLine) {
            throw new DomainException(
                'La evidencia de inventario no corresponde a la recepción.'
            );
        }

        $quantity = InventoryQuantity::positive(
            $this->received_quantity
        );

        InventoryQuantity::assertFitsScale(
            $quantity,
            (int) $orderLine->quantity_scale,
            'La cantidad recibida'
        );

        if (
            ! InventoryQuantity::equal(
                $quantity,
                $movementLine->base_quantity
            )
            || $movementLine->condition !== $this->condition
        ) {
            throw new DomainException(
                'La cantidad o condición no coincide con la línea de inventario.'
            );
        }

        $this->received_quantity = $quantity;
        $this->actual_unit_cost_minor =
            PurchaseMoney::nonNegative(
                (int) $this->actual_unit_cost_minor,
                'El costo unitario real'
            );
        $this->subtotal_minor = PurchaseMoney::subtotal(
            $quantity,
            (int) $this->actual_unit_cost_minor
        );
    }
}
