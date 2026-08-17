<?php

namespace App\Domain\Commerce;

use App\Models\CustomerReceivable;
use App\Models\CustomerReceivableInstallment;
use App\Models\CustomerReceivableInstallmentPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CustomerReceivableInstallmentScheduler
{
    public function schedule(
        CustomerReceivable $receivable,
        int $installmentCount,
        User $actor
    ): CustomerReceivableInstallmentPlan {
        if (
            $installmentCount < 2
            || $installmentCount > 120
        ) {
            throw new DomainException(
                'Las cuotas propias requieren entre 2 y 120 vencimientos.'
            );
        }

        return DB::transaction(function () use (
            $receivable,
            $installmentCount,
            $actor
        ): CustomerReceivableInstallmentPlan {
            $locked = CustomerReceivable::query()
                ->forOrganization(
                    (int) $receivable->organization_id
                )
                ->whereKey($receivable->id)
                ->with('sale')
                ->lockForUpdate()
                ->first();

            if (
                ! $locked
                || $locked->due_on === null
                || (int) $locked->amount_minor
                    < $installmentCount
            ) {
                throw new DomainException(
                    'La deuda no admite ese cronograma de cuotas propias.'
                );
            }

            $existing =
                CustomerReceivableInstallmentPlan::query()
                    ->forOrganization(
                        (int) $locked->organization_id
                    )
                    ->where(
                        'customer_receivable_id',
                        $locked->id
                    )
                    ->first();

            if ($existing) {
                if (
                    (int) $existing->installment_count
                        !== $installmentCount
                    || ! $existing->first_due_on
                        ->isSameDay($locked->due_on)
                    || $existing->strategy
                        !== CustomerReceivableInstallmentPlan::
                            STRATEGY_EQUAL_MONTHLY_FIFO_V1
                ) {
                    throw new DomainException(
                        'La deuda ya posee otro cronograma de cuotas propias.'
                    );
                }

                return $existing;
            }

            if (CustomerReceivableInstallment::query()
                ->forOrganization(
                    (int) $locked->organization_id
                )
                ->where(
                    'customer_receivable_id',
                    $locked->id
                )
                ->exists()
            ) {
                throw new DomainException(
                    'La deuda posee cuotas huérfanas sin cronograma reconocido.'
                );
            }

            $baseMinor = intdiv(
                (int) $locked->amount_minor,
                $installmentCount
            );
            $firstDueOn = $locked->due_on->startOfDay();
            $rows = [];
            $now = CarbonImmutable::now();

            for (
                $sequence = 1;
                $sequence <= $installmentCount;
                $sequence++
            ) {
                $amountMinor =
                    $sequence === $installmentCount
                        ? (int) $locked->amount_minor
                            - (
                                $baseMinor
                                * ($installmentCount - 1)
                            )
                        : $baseMinor;

                $dueOn = $firstDueOn
                    ->addMonthsNoOverflow($sequence - 1);

                $fingerprint = $this->fingerprint([
                    'receivable_public_id' =>
                        $locked->public_id,
                    'sequence' => $sequence,
                    'installment_count' =>
                        $installmentCount,
                    'due_on' => $dueOn->toDateString(),
                    'amount_minor' => $amountMinor,
                    'strategy' =>
                        CustomerReceivableInstallmentPlan::
                            STRATEGY_EQUAL_MONTHLY_FIFO_V1,
                ]);

                $row =
                    CustomerReceivableInstallment::query()
                        ->create([
                            'organization_id' =>
                                $locked->organization_id,
                            'customer_receivable_id' =>
                                $locked->id,
                            'sequence' => $sequence,
                            'due_on' =>
                                $dueOn->toDateString(),
                            'amount_minor' => $amountMinor,
                            'fingerprint' => $fingerprint,
                            'created_at' => $now,
                        ]);

                $rows[] = [
                    'sequence' => $sequence,
                    'public_id' => $row->public_id,
                    'due_on' => $dueOn->toDateString(),
                    'amount_minor' => $amountMinor,
                    'fingerprint' => $fingerprint,
                ];
            }

            return CustomerReceivableInstallmentPlan::query()
                ->create([
                    'organization_id' =>
                        $locked->organization_id,
                    'customer_receivable_id' =>
                        $locked->id,
                    'installment_count' =>
                        $installmentCount,
                    'first_due_on' =>
                        $firstDueOn->toDateString(),
                    'strategy' =>
                        CustomerReceivableInstallmentPlan::
                            STRATEGY_EQUAL_MONTHLY_FIFO_V1,
                    'fingerprint' => $this->fingerprint([
                        'receivable_public_id' =>
                            $locked->public_id,
                        'installment_count' =>
                            $installmentCount,
                        'first_due_on' =>
                            $firstDueOn->toDateString(),
                        'strategy' =>
                            CustomerReceivableInstallmentPlan::
                                STRATEGY_EQUAL_MONTHLY_FIFO_V1,
                        'rows' => $rows,
                        'created_by_user_id' => $actor->id,
                    ]),
                    'created_by_user_id' => $actor->id,
                    'created_at' => $now,
                ])->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo consolidarse el cronograma de cuotas propias.',
                previous: $exception
            );
        }
    }
}
