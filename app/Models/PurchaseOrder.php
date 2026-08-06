<?php

namespace App\Models;

use App\Domain\Purchase\PurchaseMoney;
use App\Domain\Purchase\PurchasePayload;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'status' => 'draft',
        'expected_logistics_cost_minor' => 0,
        'merchandise_subtotal_minor' => 0,
        'expected_total_minor' => 0,
    ];

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_id',
        'status',
        'currency_code',
        'expected_logistics_cost_minor',
        'merchandise_subtotal_minor',
        'expected_total_minor',
        'notes',
        'idempotency_key',
        'fingerprint',
        'created_by_user_id',
        'issued_by_user_id',
        'issued_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order): void {
            if (blank($order->public_id)) {
                $order->public_id = (string) Str::uuid();
            }
        });

        static::saving(function (PurchaseOrder $order): void {
            $order->normalize();
            $order->guardOrganization();
            $order->guardSupplier();
            $order->guardState();
        });

        static::deleting(function (PurchaseOrder $order): void {
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException(
                    'Solo una orden de compra borrador puede eliminarse físicamente.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_logistics_cost_minor' => 'integer',
            'merchandise_subtotal_minor' => 'integer',
            'expected_total_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)
            ->orderBy('sequence');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)
            ->orderBy('received_at')
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'issued_by_user_id'
        );
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by_user_id'
        );
    }

    private function normalize(): void
    {
        $this->currency_code = PurchasePayload::currencyCode(
            (string) $this->currency_code
        );
        $this->notes = PurchasePayload::optionalText(
            $this->notes,
            'Las notas de la orden',
            4000
        );
        $this->cancellation_reason = PurchasePayload::optionalText(
            $this->cancellation_reason,
            'El motivo de cancelación',
            1000
        );

        PurchaseMoney::nonNegative(
            (int) $this->expected_logistics_cost_minor,
            'El costo logístico esperado'
        );
        PurchaseMoney::nonNegative(
            (int) $this->merchandise_subtotal_minor,
            'El subtotal de mercadería'
        );

        $expectedTotal = PurchaseMoney::add(
            (int) $this->merchandise_subtotal_minor,
            (int) $this->expected_logistics_cost_minor,
            'El total esperado'
        );

        if ((int) $this->expected_total_minor !== $expectedTotal) {
            throw new DomainException(
                'El total esperado no coincide con mercadería y logística.'
            );
        }

        if (
            blank($this->idempotency_key)
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
        ) {
            throw new DomainException(
                'La orden debe conservar idempotencia y huella válidas.'
            );
        }
    }

    private function guardOrganization(): void
    {
        if (
            $this->exists
            && $this->isDirty('organization_id')
        ) {
            throw new DomainException(
                'La organización de la orden de compra es inmutable.'
            );
        }
    }

    private function guardSupplier(): void
    {
        $matches = Supplier::query()
            ->whereKey($this->supplier_id)
            ->where('organization_id', $this->organization_id)
            ->exists();

        if (! $matches) {
            throw new DomainException(
                'El proveedor no pertenece a la organización de la orden.'
            );
        }
    }

    private function guardState(): void
    {
        $status = $this->status instanceof PurchaseOrderStatus
            ? $this->status
            : PurchaseOrderStatus::tryFrom((string) $this->status);

        if (! $status) {
            throw new DomainException(
                'El estado de la orden de compra es inválido.'
            );
        }

        if ($this->exists) {
            $original = PurchaseOrderStatus::tryFrom(
                (string) $this->getRawOriginal('status')
            );

            if (! $original) {
                throw new DomainException(
                    'El estado anterior de la orden es inválido.'
                );
            }

            $allowed = match ($original) {
                PurchaseOrderStatus::Draft => [
                    PurchaseOrderStatus::Draft,
                    PurchaseOrderStatus::Issued,
                ],
                PurchaseOrderStatus::Issued => [
                    PurchaseOrderStatus::Issued,
                    PurchaseOrderStatus::PartiallyReceived,
                    PurchaseOrderStatus::Received,
                    PurchaseOrderStatus::Cancelled,
                ],
                PurchaseOrderStatus::PartiallyReceived => [
                    PurchaseOrderStatus::PartiallyReceived,
                    PurchaseOrderStatus::Received,
                ],
                PurchaseOrderStatus::Received => [
                    PurchaseOrderStatus::Received,
                ],
                PurchaseOrderStatus::Cancelled => [
                    PurchaseOrderStatus::Cancelled,
                ],
            };

            if (! in_array($status, $allowed, true)) {
                throw new DomainException(
                    'La transición de estado de la orden no está permitida.'
                );
            }

            if (
                $original !== PurchaseOrderStatus::Draft
                && $this->isDirty([
                    'organization_id',
                    'public_id',
                    'supplier_id',
                    'currency_code',
                    'expected_logistics_cost_minor',
                    'merchandise_subtotal_minor',
                    'expected_total_minor',
                    'notes',
                    'idempotency_key',
                    'fingerprint',
                    'created_by_user_id',
                    'issued_by_user_id',
                    'issued_at',
                ])
            ) {
                throw new DomainException(
                    'La información comercial de una orden emitida es inmutable.'
                );
            }

            if (
                $original->isClosed()
                && $this->isDirty()
            ) {
                throw new DomainException(
                    'Una orden de compra cerrada es inmutable.'
                );
            }
        }

        if ($status === PurchaseOrderStatus::Draft) {
            if (
                $this->issued_at !== null
                || $this->issued_by_user_id !== null
                || $this->cancelled_at !== null
                || $this->cancelled_by_user_id !== null
                || $this->cancellation_reason !== null
            ) {
                throw new DomainException(
                    'Una orden borrador no posee datos de emisión o cancelación.'
                );
            }

            return;
        }

        if (
            $this->issued_at === null
            || $this->issued_by_user_id === null
        ) {
            throw new DomainException(
                'Una orden emitida requiere fecha y responsable.'
            );
        }

        if ($status === PurchaseOrderStatus::Cancelled) {
            if (
                $this->cancelled_at === null
                || $this->cancelled_by_user_id === null
                || $this->cancellation_reason === null
            ) {
                throw new DomainException(
                    'La cancelación requiere fecha, responsable y motivo.'
                );
            }

            return;
        }

        if (
            $this->cancelled_at !== null
            || $this->cancelled_by_user_id !== null
            || $this->cancellation_reason !== null
        ) {
            throw new DomainException(
                'Solo una orden cancelada posee datos de cancelación.'
            );
        }
    }
}
