<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SupplierAdvanceRequest extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'supplier_id',
        'origin_financial_account_id',
        'requested_by_user_id',
        'amount_minor',
        'currency_code',
        'request_note',
        'request_idempotency_key',
        'fingerprint',
        'requested_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            SupplierAdvanceRequest $request
        ): void {
            if (blank($request->public_id)) {
                $request->public_id =
                    (string) Str::uuid();
            }

            if (
                (int) $request->amount_minor <= 0
                || strlen(
                    (string) $request->currency_code
                ) !== 3
                || (string) $request->currency_code
                    !== strtoupper(
                        (string) $request->currency_code
                    )
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
                    'La solicitud de anticipo no conserva importe, moneda, idempotencia, actor o tiempo válidos.'
                );
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una solicitud de anticipo de proveedor es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una solicitud de anticipo de proveedor no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' =>
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

    public function decision(): HasOne
    {
        return $this->hasOne(
            SupplierAdvanceDecision::class,
            'supplier_advance_request_id'
        );
    }

    public function advance(): HasOne
    {
        return $this->hasOne(
            SupplierAdvance::class,
            'supplier_advance_request_id'
        );
    }
}
