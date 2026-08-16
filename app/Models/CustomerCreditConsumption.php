<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CustomerCreditConsumption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'commerce_sale_id',
        'payment_position',
        'currency_code',
        'amount_minor',
        'consumed_by_user_id',
        'consumed_at',
        'idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'business_party_id' => 'integer',
            'commerce_sale_id' => 'integer',
            'payment_position' => 'integer',
            'amount_minor' => 'integer',
            'consumed_by_user_id' => 'integer',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $consumption): void {
            $consumption->public_id ??= (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new DomainException(
                'El consumo de saldo a favor es inmutable.'
            );
        });

        static::deleting(function (): never {
            throw new DomainException(
                'El consumo de saldo a favor no admite borrado físico.'
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

    public function party(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            CommerceSale::class,
            'commerce_sale_id'
        );
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'consumed_by_user_id'
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(
            CustomerCreditConsumptionAllocation::class,
            'customer_credit_consumption_id'
        )->orderBy('sequence');
    }
}
