<?php

namespace App\Models;

use App\Enums\PurchasePaymentRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchasePaymentGroupRequest extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_id',
        'beneficiary_business_party_id',
        'origin_financial_account_id',
        'requested_by_user_id',
        'currency_code',
        'status',
        'request_note',
        'request_idempotency_key',
        'fingerprint',
        'requested_at',
        'approved_by_user_id',
        'approval_note',
        'approval_idempotency_key',
        'approval_fingerprint',
        'approved_at',
        'resolved_by_user_id',
        'resolution_note',
        'resolution_idempotency_key',
        'resolved_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            PurchasePaymentGroupRequest $request
        ): void {
            if (blank($request->public_id)) {
                $request->public_id =
                    (string) Str::uuid();
            }

            if (
                $request->status
                    !== PurchasePaymentRequestStatus::Pending
                || strlen(
                    (string) $request->currency_code
                ) !== 3
                || blank(
                    $request->request_idempotency_key
                )
                || strlen(
                    (string) $request->fingerprint
                ) !== 64
                || $request->requested_by_user_id
                    === null
                || $request->requested_at === null
                || $request->created_at === null
            ) {
                throw new DomainException(
                    'La solicitud agrupada no conserva estado, moneda, idempotencia, actor o tiempo válidos.'
                );
            }
        });

        static::updating(function (
            PurchasePaymentGroupRequest $request
        ): void {
            foreach ([
                'organization_id',
                'public_id',
                'supplier_id',
                'beneficiary_business_party_id',
                'origin_financial_account_id',
                'requested_by_user_id',
                'currency_code',
                'request_note',
                'request_idempotency_key',
                'fingerprint',
                'requested_at',
                'created_at',
            ] as $field) {
                if ($request->isDirty($field)) {
                    throw new DomainException(
                        'Los hechos base de una solicitud agrupada son inmutables.'
                    );
                }
            }
        });

        static::deleting(
            fn () => throw new DomainException(
                'Una solicitud agrupada de pago no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'status' =>
                PurchasePaymentRequestStatus::class,
            'requested_at' =>
                'immutable_datetime',
            'approved_at' =>
                'immutable_datetime',
            'resolved_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class
        );
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'beneficiary_business_party_id'
        );
    }

    public function originFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'origin_financial_account_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by_user_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by_user_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentGroupRequestItem::class,
            'purchase_payment_group_request_id'
        )->orderBy('id');
    }
}
