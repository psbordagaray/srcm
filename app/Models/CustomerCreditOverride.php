<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerCreditOverride extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'commerce_sale_id',
        'customer_credit_policy_id',
        'currency_code',
        'amount_minor',
        'exposure_before_minor',
        'projected_exposure_minor',
        'overdue_minor',
        'oldest_days_overdue',
        'limit_minor',
        'over_limit',
        'overdue',
        'snapshot_fingerprint',
        'reason',
        'approved_by_user_id',
        'approved_at',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerCreditOverride $override
        ): void {
            if (blank($override->public_id)) {
                $override->public_id = (string) Str::uuid();
            }

            $override->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una excepción de crédito autorizada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una excepción de crédito autorizada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'exposure_before_minor' => 'integer',
            'projected_exposure_minor' => 'integer',
            'overdue_minor' => 'integer',
            'oldest_days_overdue' => 'integer',
            'limit_minor' => 'integer',
            'over_limit' => 'boolean',
            'overdue' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
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

    public function policy(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCreditPolicy::class,
            'customer_credit_policy_id'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        if (
            (int) $this->amount_minor <= 0
            || (int) $this->exposure_before_minor < 0
            || (int) $this->projected_exposure_minor
                !== (int) $this->exposure_before_minor
                    + (int) $this->amount_minor
            || (int) $this->overdue_minor < 0
            || (int) $this->oldest_days_overdue < 0
            || (
                $this->limit_minor !== null
                && (int) $this->limit_minor < 0
            )
            || (
                ! (bool) $this->over_limit
                && ! (bool) $this->overdue
            )
            || (
                (bool) $this->over_limit
                && (
                    $this->limit_minor === null
                    || (int) $this->projected_exposure_minor
                        <= (int) $this->limit_minor
                )
            )
            || (
                (bool) $this->overdue
                !== ((int) $this->overdue_minor > 0)
            )
            || blank($this->snapshot_fingerprint)
            || strlen(
                (string) $this->snapshot_fingerprint
            ) !== 64
            || blank($this->reason)
            || mb_strlen((string) $this->reason) > 2000
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->approved_by_user_id === null
            || $this->approved_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La excepción de crédito no conserva riesgo, motivo y trazabilidad válidos.'
            );
        }

        $sale = CommerceSale::query()
            ->whereKey($this->commerce_sale_id)
            ->where('organization_id', $this->organization_id)
            ->where('status', 'building')
            ->first();

        $policy = $this->customer_credit_policy_id === null
            ? null
            : CustomerCreditPolicy::query()
                ->whereKey(
                    $this->customer_credit_policy_id
                )
                ->where(
                    'organization_id',
                    $this->organization_id
                )
                ->where(
                    'business_party_id',
                    $this->business_party_id
                )
                ->where(
                    'currency_code',
                    $this->currency_code
                )
                ->first();

        $administrator = OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'user_id',
                $this->approved_by_user_id
            )
            ->where('active', true)
            ->first();

        if (
            ! $sale
            || (int) $sale->customer_business_party_id
                !== (int) $this->business_party_id
            || (string) $sale->currency_code
                !== (string) $this->currency_code
            || (
                $this->customer_credit_policy_id !== null
                && (
                    ! $policy
                    || $this->limit_minor === null
                    || (int) $policy->limit_minor
                        !== (int) $this->limit_minor
                )
            )
            || (
                $this->customer_credit_policy_id === null
                && $this->limit_minor !== null
            )
            || ! $administrator?->role
                ->canOverrideCustomerCredit()
        ) {
            throw new DomainException(
                'La excepción de crédito no coincide con venta, política y Administrador.'
            );
        }
    }
}
