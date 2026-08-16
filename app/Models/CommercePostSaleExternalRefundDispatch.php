<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommercePostSaleExternalRefundDispatch extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_external_refund_instruction_id',
        'financial_provider_connection_id',
        'financial_account_id',
        'provider_key',
        'provider_idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleExternalRefundDispatch $dispatch
        ): void {
            if (blank($dispatch->public_id)) {
                $dispatch->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Un despacho de reembolso externo es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un despacho de reembolso externo no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function instruction(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExternalRefundInstruction::class,
            'commerce_post_sale_external_refund_instruction_id'
        );
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderConnection::class,
            'financial_provider_connection_id'
        );
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleExternalRefundEvidence::class,
            'commerce_post_sale_external_refund_dispatch_id'
        )
            ->orderBy('observed_at')
            ->orderBy('id');
    }
}
