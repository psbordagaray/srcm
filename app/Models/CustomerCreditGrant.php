<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerCreditGrant extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'commerce_post_sale_resolution_id',
        'currency_code',
        'amount_minor',
        'granted_by_user_id',
        'granted_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerCreditGrant $grant
        ): void {
            if (blank($grant->public_id)) {
                $grant->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Un saldo a favor otorgado es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Un saldo a favor otorgado no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'granted_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleResolution::class,
            'commerce_post_sale_resolution_id'
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
