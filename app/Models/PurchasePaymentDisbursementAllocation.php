<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePaymentDisbursementAllocation extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'purchase_payment_disbursement_id',
        'purchase_obligation_id',
        'purchase_payment_request_id',
        'purchase_payment_group_request_item_id',
        'amount_minor',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentDisbursementAllocation $allocation
        ): void {
            $allocation->guardCreation();
        });

        static::updating(
            fn () => throw new DomainException(
                'Una imputación de desembolso es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una imputación de desembolso no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentDisbursement::class,
            'purchase_payment_disbursement_id'
        );
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseObligation::class,
            'purchase_obligation_id'
        );
    }

    public function individualRequest(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentRequest::class,
            'purchase_payment_request_id'
        );
    }

    public function groupRequestItem(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentGroupRequestItem::class,
            'purchase_payment_group_request_item_id'
        );
    }

    private function guardCreation(): void
    {
        $individualId =
            $this->purchase_payment_request_id;
        $groupItemId =
            $this->purchase_payment_group_request_item_id;

        if (
            ($individualId === null)
                === ($groupItemId === null)
        ) {
            throw new DomainException(
                'La imputación debe provenir exactamente de una autorización individual o item agrupado.'
            );
        }

        $disbursement = PurchasePaymentDisbursement::query()
            ->whereKey(
                $this->purchase_payment_disbursement_id
            )
            ->where(
                'organization_id',
                $this->organization_id
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
            ->first();

        if (! $disbursement || ! $obligation) {
            throw new DomainException(
                'La imputación no conserva desembolso y obligación de la misma organización.'
            );
        }

        if ($individualId !== null) {
            $request = PurchasePaymentRequest::query()
                ->whereKey($individualId)
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->where(
                    'amount_minor',
                    $this->amount_minor
                )
                ->first();

            if (
                ! $request
                || (int) $disbursement
                    ->purchase_payment_request_id
                    !== (int) $request->id
                || $disbursement
                    ->purchase_payment_group_request_id
                    !== null
            ) {
                throw new DomainException(
                    'La imputación individual no conserva su autorización.'
                );
            }
        } else {
            $item = PurchasePaymentGroupRequestItem::query()
                ->whereKey($groupItemId)
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->where(
                    'amount_minor',
                    $this->amount_minor
                )
                ->first();

            if (
                ! $item
                || (int) $disbursement
                    ->purchase_payment_group_request_id
                    !== (int) $item
                        ->purchase_payment_group_request_id
                || $disbursement
                    ->purchase_payment_request_id
                    !== null
            ) {
                throw new DomainException(
                    'La imputación agrupada no conserva su item autorizado.'
                );
            }
        }

        $legacyExecuted =
            (int) PurchasePaymentExecution::query()
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->sum('amount_minor');

        $newExecuted =
            (int) self::query()
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->sum('amount_minor');

        $noteApplied =
            (int) SupplierCreditApplication::query()
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->sum('amount_minor');

        $advanceApplied =
            (int) SupplierAdvanceApplication::query()
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
            || $legacyExecuted
                + $newExecuted
                + $noteApplied
                + $advanceApplied
                + (int) $this->amount_minor
                > (int) $obligation->amount_minor
            || strlen(
                (string) $this->fingerprint
            ) !== 64
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La imputación excede el saldo de obligación o carece de huella/tiempo.'
            );
        }
    }
}
