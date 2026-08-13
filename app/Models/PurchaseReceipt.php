<?php

namespace App\Models;

use App\Domain\Purchase\PurchaseMoney;
use App\Domain\Purchase\PurchasePayload;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseReceipt extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'purchase_order_id',
        'supplier_id',
        'inventory_movement_id',
        'document_reference',
        'normalized_document_reference',
        'received_at',
        'confirmed_at',
        'received_by_user_id',
        'logistics_cost_minor',
        'merchandise_total_minor',
        'actual_total_minor',
        'idempotency_key',
        'fingerprint',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseReceipt $receipt): void {
            if (blank($receipt->public_id)) {
                $receipt->public_id = (string) Str::uuid();
            }

            $receipt->guardCreation();
        });

        static::updating(function (): never {
            throw new DomainException(
                'Una recepción de compra confirmada es inmutable.'
            );
        });

        static::deleting(function (): never {
            throw new DomainException(
                'Una recepción de compra confirmada no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'logistics_cost_minor' => 'integer',
            'merchandise_total_minor' => 'integer',
            'actual_total_minor' => 'integer',
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

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class)
            ->orderBy('sequence');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(PurchaseObligation::class)
            ->orderBy('id');
    }

    private function guardCreation(): void
    {
        $order = PurchaseOrder::query()
            ->whereKey($this->purchase_order_id)
            ->where('organization_id', $this->organization_id)
            ->where('supplier_id', $this->supplier_id)
            ->first();

        if (
            ! $order
            || ! $order->status->acceptsReceipts()
        ) {
            throw new DomainException(
                'La recepción no corresponde a una orden emitida que admita ingresos.'
            );
        }

        $movement = InventoryMovement::query()
            ->whereKey($this->inventory_movement_id)
            ->where('organization_id', $this->organization_id)
            ->where(
                'status',
                InventoryMovementStatus::Confirmed->value
            )
            ->where(
                'type',
                InventoryMovementType::Receipt->value
            )
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $this->public_id)
            ->first();

        if (! $movement) {
            throw new DomainException(
                'La recepción requiere su movimiento de inventario confirmado exacto.'
            );
        }

        $document = PurchasePayload::documentReference(
            $this->document_reference
        );

        if (
            $document['normalized']
                !== $this->normalized_document_reference
        ) {
            throw new DomainException(
                'La identidad documental normalizada no coincide.'
            );
        }

        PurchaseMoney::nonNegative(
            (int) $this->logistics_cost_minor,
            'El costo logístico real'
        );
        PurchaseMoney::nonNegative(
            (int) $this->merchandise_total_minor,
            'El total real de mercadería'
        );

        $actualTotal = PurchaseMoney::add(
            (int) $this->merchandise_total_minor,
            (int) $this->logistics_cost_minor,
            'El total real'
        );

        if ((int) $this->actual_total_minor !== $actualTotal) {
            throw new DomainException(
                'El total real no coincide con mercadería y logística.'
            );
        }

        if (
            $this->received_at === null
            || $this->confirmed_at === null
            || $this->received_by_user_id === null
            || blank($this->idempotency_key)
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
        ) {
            throw new DomainException(
                'La recepción no conserva confirmación, idempotencia y huella válidas.'
            );
        }

        $this->notes = PurchasePayload::optionalText(
            $this->notes,
            'Las notas de recepción',
            4000
        );
    }
}
