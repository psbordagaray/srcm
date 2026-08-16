<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommercePostSaleExternalRefundInstruction extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_resolution_id',
        'original_commerce_payment_id',
        'financial_account_id',
        'financial_provider_connection_id',
        'requested_by_user_id',
        'amount_minor',
        'currency_code',
        'idempotency_key',
        'fingerprint',
        'requested_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleExternalRefundInstruction $instruction
        ): void {
            if (blank($instruction->public_id)) {
                $instruction->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una instrucción de reembolso externo es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una instrucción de reembolso externo no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleResolution::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(
            CommercePayment::class,
            'original_commerce_payment_id'
        );
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(
            FinancialProviderConnection::class,
            'financial_provider_connection_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }
}
