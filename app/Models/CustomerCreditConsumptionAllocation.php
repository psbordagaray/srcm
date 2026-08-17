<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditConsumptionAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'customer_credit_consumption_id',
        'sequence',
        'customer_credit_grant_id',
        'commerce_post_sale_exchange_credit_grant_id',
        'customer_advance_id',
        'amount_minor',
        'fingerprint',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'customer_credit_consumption_id' => 'integer',
            'sequence' => 'integer',
            'customer_credit_grant_id' => 'integer',
            'commerce_post_sale_exchange_credit_grant_id' => 'integer',
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException(
                'La imputación de saldo a favor es inmutable.'
            );
        });

        static::deleting(function (): never {
            throw new DomainException(
                'La imputación de saldo a favor no admite borrado físico.'
            );
        });
    }

    public function scopeForOrganization(
        Builder $query,
        int $organizationId
    ): Builder {
        return $query->where(
            $query->qualifyColumn('organization_id'),
            $organizationId
        );
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCreditConsumption::class,
            'customer_credit_consumption_id'
        );
    }

    public function customerCreditGrant(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCreditGrant::class,
            'customer_credit_grant_id'
        );
    }

    public function exchangeCreditGrant(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeCreditGrant::class,
            'commerce_post_sale_exchange_credit_grant_id'
        );
    }

    public function customerAdvance(): BelongsTo
    {
        return $this->belongsTo(
            CustomerAdvance::class,
            'customer_advance_id'
        );
    }
}