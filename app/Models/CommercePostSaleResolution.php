<?php

namespace App\Models;

use App\Enums\CommercePostSaleResolutionOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CommercePostSaleResolution extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_request_id',
        'outcome',
        'currency_code',
        'preferred_original_payment_id',
        'reason',
        'notes',
        'resolved_by_user_id',
        'resolved_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleResolution $resolution
        ): void {
            if (blank($resolution->public_id)) {
                $resolution->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una resolución de posventa confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una resolución de posventa confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'outcome' =>
                CommercePostSaleResolutionOutcome::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleRequest::class,
            'commerce_post_sale_request_id'
        );
    }

    public function preferredOriginalPayment(): BelongsTo
    {
        return $this->belongsTo(
            CommercePayment::class,
            'preferred_original_payment_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleResolutionLine::class
        )->orderBy('id');
    }

    public function customerCreditGrant(): HasOne
    {
        return $this->hasOne(
            CustomerCreditGrant::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function cashRefundExecution(): HasOne
    {
        return $this->hasOne(
            CommercePostSaleCashRefundExecution::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function externalRefundInstruction(): HasOne
    {
        return $this->hasOne(
            CommercePostSaleExternalRefundInstruction::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function recognizedAmountMinor(): int
    {
        if ($this->relationLoaded('lines')) {
            return (int) $this->lines->sum(
                'recognized_amount_minor'
            );
        }

        return (int) $this->lines()
            ->sum('recognized_amount_minor');
    }
}
