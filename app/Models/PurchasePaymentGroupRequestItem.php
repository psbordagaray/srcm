<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePaymentGroupRequestItem extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'purchase_payment_group_request_id',
        'purchase_obligation_id',
        'amount_minor',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentGroupRequestItem $item
        ): void {
            if (
                (int) $item->amount_minor <= 0
                || strlen(
                    (string) $item->fingerprint
                ) !== 64
                || $item->created_at === null
            ) {
                throw new DomainException(
                    'La imputación de una solicitud agrupada debe conservar importe, huella y tiempo.'
                );
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una imputación de solicitud agrupada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una imputación de solicitud agrupada no puede eliminarse.'
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePaymentGroupRequest::class,
            'purchase_payment_group_request_id'
        );
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseObligation::class,
            'purchase_obligation_id'
        );
    }
}
