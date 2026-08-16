<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePostSaleExchangeCreditGrant extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'commerce_post_sale_exchange_execution_id',
        'business_party_id',
        'amount_minor',
        'currency_code',
        'granted_by_user_id',
        'granted_at',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException(
                'Un crédito por diferencia de cambio es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un crédito por diferencia de cambio no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'granted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeExecution::class,
            'commerce_post_sale_exchange_execution_id'
        );
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'granted_by_user_id'
        );
    }
}
