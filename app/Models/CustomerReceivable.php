<?php

namespace App\Models;

use App\Enums\CommerceSaleStatus;
use App\Enums\CustomerCreditDecisionType;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CustomerReceivable extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'commerce_sale_id',
        'currency_code',
        'amount_minor',
        'due_on',
        'customer_credit_policy_id',
        'customer_credit_override_id',
        'credit_decision',
        'credit_limit_minor',
        'credit_exposure_before_minor',
        'credit_projected_exposure_minor',
        'credit_overdue_minor',
        'credit_oldest_days_overdue',
        'credit_snapshot_fingerprint',
        'idempotency_key',
        'fingerprint',
        'recognized_by_user_id',
        'recognized_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerReceivable $receivable
        ): void {
            if (blank($receivable->public_id)) {
                $receivable->public_id =
                    (string) Str::uuid();
            }

            $receivable->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una cuenta por cobrar reconocida es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una cuenta por cobrar reconocida no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_on' => 'immutable_date',
            'credit_decision' =>
                CustomerCreditDecisionType::class,
            'credit_limit_minor' => 'integer',
            'credit_exposure_before_minor' =>
                'integer',
            'credit_projected_exposure_minor' =>
                'integer',
            'credit_overdue_minor' => 'integer',
            'credit_oldest_days_overdue' =>
                'integer',
            'recognized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            CommerceSale::class,
            'commerce_sale_id'
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function recognizedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recognized_by_user_id'
        );
    }

    public function creditPolicy(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCreditPolicy::class,
            'customer_credit_policy_id'
        );
    }

    public function creditOverride(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCreditOverride::class,
            'customer_credit_override_id'
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(
            CustomerCollectionAllocation::class,
            'customer_receivable_id'
        )->orderBy('sequence');
    }

    private function guardCreation(): void
    {
        $sale = CommerceSale::query()
            ->whereKey($this->commerce_sale_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'status',
                CommerceSaleStatus::Building->value
            )
            ->first();

        if (
            ! $sale
            || $sale->customer_business_party_id === null
            || (int) $sale->customer_business_party_id
                !== (int) $this->business_party_id
            || (string) $sale->currency_code
                !== (string) $this->currency_code
            || (int) $this->amount_minor <= 0
            || (int) $this->amount_minor
                > (int) $sale->total_minor
        ) {
            throw new DomainException(
                'La cuenta por cobrar no coincide con una venta en preparación y un cliente identificado.'
            );
        }

        $isActiveCustomer = BusinessParty::query()
            ->whereKey($this->business_party_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->whereHas(
                'customer',
                fn ($customer) =>
                    $customer->where('active', true)
            )
            ->exists();

        if (! $isActiveCustomer) {
            throw new DomainException(
                'La cuenta por cobrar requiere un cliente activo de la organización.'
            );
        }

        if (
            $this->due_on !== null
            && CarbonImmutable::parse($this->due_on)
                ->startOfDay()
                ->lt($sale->sold_at->startOfDay())
        ) {
            throw new DomainException(
                'El vencimiento de la cuenta por cobrar no puede ser anterior a la venta.'
            );
        }

        $decision = $this->credit_decision
            instanceof CustomerCreditDecisionType
                ? $this->credit_decision
                : CustomerCreditDecisionType::tryFrom(
                    (string) $this->credit_decision
                );

        if (
            ! $decision
            || $this->credit_exposure_before_minor === null
            || $this->credit_projected_exposure_minor === null
            || $this->credit_overdue_minor === null
            || $this->credit_oldest_days_overdue === null
            || (
                (int) $this->credit_projected_exposure_minor
                !== (int) $this->credit_exposure_before_minor
                    + (int) $this->amount_minor
            )
            || blank($this->credit_snapshot_fingerprint)
            || strlen(
                (string) $this->credit_snapshot_fingerprint
            ) !== 64
        ) {
            throw new DomainException(
                'La cuenta por cobrar requiere evidencia de decisión de crédito.'
            );
        }

        if (
            $decision
                === CustomerCreditDecisionType::LegacyAdmin
            && (
                $this->customer_credit_policy_id !== null
                || $this->customer_credit_override_id
                    !== null
                || $this->credit_limit_minor !== null
            )
        ) {
            throw new DomainException(
                'La decisión legacy no puede fingir una política configurada.'
            );
        }

        if (
            $decision
                === CustomerCreditDecisionType::WithinPolicy
            && (
                $this->customer_credit_policy_id === null
                || $this->customer_credit_override_id
                    !== null
                || $this->credit_limit_minor === null
                || (int) $this->credit_overdue_minor > 0
                || (
                    (int) $this
                        ->credit_projected_exposure_minor
                    > (int) $this->credit_limit_minor
                )
            )
        ) {
            throw new DomainException(
                'La decisión dentro de política no coincide con la exposición.'
            );
        }

        if (
            $decision
                === CustomerCreditDecisionType::AdminOverride
            && $this->customer_credit_override_id === null
        ) {
            throw new DomainException(
                'La decisión excepcional requiere su autorización Administrador.'
            );
        }

        if (
            blank($this->idempotency_key)
            || mb_strlen(
                (string) $this->idempotency_key
            ) > 90
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->recognized_by_user_id === null
            || $this->recognized_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La cuenta por cobrar debe conservar idempotencia, huella, responsable y tiempo.'
            );
        }
    }
}
