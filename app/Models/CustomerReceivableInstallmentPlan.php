<?php

namespace App\Models;

use App\Enums\CommerceSaleStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerReceivableInstallmentPlan extends Model
{
    use BelongsToOrganization;

    public const STRATEGY_EQUAL_MONTHLY_FIFO_V1 =
        'equal_monthly_fifo_v1';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'customer_receivable_id',
        'installment_count',
        'first_due_on',
        'strategy',
        'fingerprint',
        'created_by_user_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerReceivableInstallmentPlan $plan
        ): void {
            if (blank($plan->public_id)) {
                $plan->public_id = (string) Str::uuid();
            }

            $plan->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Un cronograma de cuotas propias reconocido es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Un cronograma de cuotas propias reconocido no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'installment_count' => 'integer',
            'first_due_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(
            CustomerReceivable::class,
            'customer_receivable_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        if (
            (int) $this->installment_count < 2
            || (int) $this->installment_count > 120
            || $this->strategy
                !== self::STRATEGY_EQUAL_MONTHLY_FIFO_V1
            || $this->first_due_on === null
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->created_by_user_id === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'El cronograma de cuotas propias no conserva cantidad, primer vencimiento, estrategia y trazabilidad válidos.'
            );
        }

        $receivable = CustomerReceivable::query()
            ->whereKey($this->customer_receivable_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->with('sale')
            ->first();

        if (
            ! $receivable
            || ! $receivable->sale
            || $receivable->sale->status
                !== CommerceSaleStatus::Building
            || $receivable->due_on === null
            || ! $receivable->due_on->isSameDay(
                $this->first_due_on
            )
            || (int) $receivable->recognized_by_user_id
                !== (int) $this->created_by_user_id
            || (int) $receivable->amount_minor
                < (int) $this->installment_count
        ) {
            throw new DomainException(
                'El cronograma no coincide con la cuenta por cobrar y la venta en preparación.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'user_id',
                $this->created_by_user_id
            )
            ->where('active', true)
            ->first();

        if (
            ! $membership
            || ! $membership->role
                ->canCreateCustomerReceivable()
        ) {
            throw new DomainException(
                'El cronograma requiere un actor habilitado para ventas a crédito.'
            );
        }

        $rows = CustomerReceivableInstallment::query()
            ->forOrganization((int) $this->organization_id)
            ->where(
                'customer_receivable_id',
                $receivable->id
            )
            ->orderBy('sequence')
            ->get();

        if (
            $rows->count()
                !== (int) $this->installment_count
            || (int) $rows->sum('amount_minor')
                !== (int) $receivable->amount_minor
        ) {
            throw new DomainException(
                'El cronograma no cubre exactamente la deuda reconocida.'
            );
        }

        $baseMinor = intdiv(
            (int) $receivable->amount_minor,
            (int) $this->installment_count
        );

        foreach ($rows as $index => $row) {
            $sequence = $index + 1;
            $expectedAmount =
                $sequence === (int) $this->installment_count
                    ? (int) $receivable->amount_minor
                        - (
                            $baseMinor
                            * (
                                (int) $this->installment_count
                                - 1
                            )
                        )
                    : $baseMinor;

            $expectedDueOn = CarbonImmutable::parse(
                $this->first_due_on
            )
                ->startOfDay()
                ->addMonthsNoOverflow($sequence - 1);

            if (
                (int) $row->sequence !== $sequence
                || (int) $row->amount_minor
                    !== $expectedAmount
                || ! $row->due_on->isSameDay(
                    $expectedDueOn
                )
            ) {
                throw new DomainException(
                    'Las cuotas propias no respetan importe mensual igual, ajuste final y vencimientos mensuales.'
                );
            }
        }
    }
}
