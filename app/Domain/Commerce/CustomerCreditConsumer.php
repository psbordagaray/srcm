<?php

namespace App\Domain\Commerce;

use App\Models\CommercePostSaleExchangeCreditGrant;
use App\Models\CommercePostSaleExchangeExecution;
use App\Enums\CustomerAdvanceStatus;
use App\Models\CommerceSale;
use App\Models\CustomerAdvance;
use App\Models\CustomerCreditConsumption;
use App\Models\CustomerCreditConsumptionAllocation;
use App\Models\CustomerCreditGrant;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CustomerCreditConsumer
{
    public function consumeForSalePayment(
        CommerceSale $sale,
        int $paymentPosition,
        int $amountMinor,
        string $idempotencyKey,
        User $actor
    ): CustomerCreditConsumption {
        return DB::transaction(function () use (
            $sale,
            $paymentPosition,
            $amountMinor,
            $idempotencyKey,
            $actor
        ): CustomerCreditConsumption {
            $organizationId =
                (int) $actor->current_organization_id;

            if (
                $organizationId <= 0
                || $paymentPosition <= 0
                || $amountMinor <= 0
            ) {
                throw new DomainException(
                    'El consumo de saldo a favor contiene datos inválidos.'
                );
            }

            if (
                ! DB::table('organizations')
                    ->where('id', $organizationId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'La organización no está activa.'
                );
            }

            $idempotencyKey =
                trim($idempotencyKey);

            if (
                $idempotencyKey === ''
                || mb_strlen($idempotencyKey) > 180
            ) {
                throw new DomainException(
                    'La clave idempotente del consumo de saldo a favor es inválida.'
                );
            }

            $membership =
                OrganizationMembership::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'user_id',
                        $actor->id
                    )
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

            if (
                ! $membership?->role
                    ->canRecordCommerceSale()
            ) {
                throw new DomainException(
                    'El usuario no puede consumir saldo a favor en una venta.'
                );
            }

            $lockedSale =
                CommerceSale::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->whereKey($sale->id)
                    ->lockForUpdate()
                    ->first();

            if (
                ! $lockedSale
                || $lockedSale->status->value
                    !== 'building'
                || $lockedSale
                    ->customer_business_party_id
                    === null
            ) {
                throw new DomainException(
                    'El saldo a favor sólo puede consumirse dentro de una venta identificada y en construcción.'
                );
            }

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'commerce_sale_id' =>
                        (int) $lockedSale->id,
                    'business_party_id' =>
                        (int) $lockedSale
                            ->customer_business_party_id,
                    'payment_position' =>
                        $paymentPosition,
                    'currency_code' =>
                        (string) $lockedSale
                            ->currency_code,
                    'amount_minor' =>
                        $amountMinor,
                ]);

            $existing =
                CustomerCreditConsumption::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->with('allocations')
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente del saldo a favor ya representa otra operación.'
                    );
                }

                return $existing;
            }

            $partyId =
                (int) $lockedSale
                    ->customer_business_party_id;
            $currencyCode =
                (string) $lockedSale
                    ->currency_code;

            $standard =
                CustomerCreditGrant::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->orderBy('granted_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $exchange =
                CommercePostSaleExchangeCreditGrant::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->orderBy('granted_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $advances =
                CustomerAdvance::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->where(
                        'status',
                        CustomerAdvanceStatus::Confirmed->value
                    )
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $sources =
                collect()
                    ->concat(
                        $standard->map(
                            fn (
                                CustomerCreditGrant $grant
                            ): array => [
                                'kind' =>
                                    'customer_credit',
                                'id' =>
                                    (int) $grant->id,
                                'amount_minor' =>
                                    (int) $grant
                                        ->amount_minor,
                                'granted_at' =>
                                    $grant
                                        ->granted_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->concat(
                        $exchange->map(
                            fn (
                                CommercePostSaleExchangeCreditGrant $grant
                            ): array => [
                                'kind' =>
                                    'exchange_credit',
                                'id' =>
                                    (int) $grant->id,
                                'amount_minor' =>
                                    (int) $grant
                                        ->amount_minor,
                                'granted_at' =>
                                    $grant
                                        ->granted_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->concat(
                        $advances->map(
                            fn (
                                CustomerAdvance $advance
                            ): array => [
                                'kind' =>
                                    'customer_advance',
                                'id' =>
                                    (int) $advance->id,
                                'amount_minor' =>
                                    (int) $advance
                                        ->amount_minor,
                                'granted_at' =>
                                    $advance
                                        ->received_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->sortBy(
                        fn (array $source): string =>
                            $source['granted_at']
                            .'|'
                            .$source['kind']
                            .'|'
                            .str_pad(
                                (string) $source['id'],
                                20,
                                '0',
                                STR_PAD_LEFT
                            )
                    )
                    ->values();

            $standardConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'customer_credit_grant_id',
                        $standard->pluck('id')
                    )
                    ->selectRaw(
                        'customer_credit_grant_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'customer_credit_grant_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'customer_credit_grant_id'
                    );

            $exchangeConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'commerce_post_sale_exchange_credit_grant_id',
                        $exchange->pluck('id')
                    )
                    ->selectRaw(
                        'commerce_post_sale_exchange_credit_grant_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'commerce_post_sale_exchange_credit_grant_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'commerce_post_sale_exchange_credit_grant_id'
                    );

            $advanceConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'customer_advance_id',
                        $advances->pluck('id')
                    )
                    ->selectRaw(
                        'customer_advance_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'customer_advance_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'customer_advance_id'
                    );

            $plan = [];
            $remaining = $amountMinor;

            foreach ($sources as $source) {
                $already = match (
                    $source['kind']
                ) {
                    'customer_credit' =>
                        (int) (
                            $standardConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    'exchange_credit' =>
                        (int) (
                            $exchangeConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    'customer_advance' =>
                        (int) (
                            $advanceConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    default => throw new DomainException(
                        'La fuente de saldo a favor no es válida.'
                    ),
                };

                $available =
                    max(
                        0,
                        $source['amount_minor']
                        - $already
                    );

                if ($available === 0) {
                    continue;
                }

                $take =
                    min($available, $remaining);

                $plan[] = [
                    'kind' =>
                        $source['kind'],
                    'id' =>
                        $source['id'],
                    'amount_minor' =>
                        $take,
                ];

                $remaining -= $take;

                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining !== 0) {
                throw new DomainException(
                    'El cliente no posee saldo a favor suficiente para este importe.'
                );
            }

            $consumption =
                CustomerCreditConsumption::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'public_id' =>
                            (string) Str::uuid(),
                        'business_party_id' =>
                            $partyId,
                        'commerce_sale_id' =>
                            $lockedSale->id,
                        'target_kind' =>
                            'sale_payment',
                        'target_id' =>
                            $lockedSale->id,
                        'commerce_post_sale_exchange_execution_id' =>
                            null,
                        'payment_position' =>
                            $paymentPosition,
                        'currency_code' =>
                            $currencyCode,
                        'amount_minor' =>
                            $amountMinor,
                        'consumed_by_user_id' =>
                            $actor->id,
                        'consumed_at' =>
                            CarbonImmutable::now(),
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'created_at' =>
                            CarbonImmutable::now(),
                    ]);

            foreach ($plan as $index => $item) {
                CustomerCreditConsumptionAllocation::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'customer_credit_consumption_id' =>
                            $consumption->id,
                        'sequence' =>
                            $index + 1,
                        'customer_credit_grant_id' =>
                            $item['kind']
                                === 'customer_credit'
                                ? $item['id']
                                : null,
                        'commerce_post_sale_exchange_credit_grant_id' =>
                            $item['kind']
                                === 'exchange_credit'
                                ? $item['id']
                                : null,
                        'customer_advance_id' =>
                            $item['kind']
                                === 'customer_advance'
                                ? $item['id']
                                : null,
                        'amount_minor' =>
                            $item['amount_minor'],
                        'fingerprint' =>
                            $this->fingerprint([
                                'consumption_id' =>
                                    (int) $consumption
                                        ->id,
                                'sequence' =>
                                    $index + 1,
                                'kind' =>
                                    $item['kind'],
                                'source_id' =>
                                    $item['id'],
                                'amount_minor' =>
                                    $item[
                                        'amount_minor'
                                    ],
                            ]),
                        'created_at' =>
                            CarbonImmutable::now(),
                    ]);
            }

            return $consumption
                ->refresh()
                ->load('allocations');
        }, 3);
    }


    public function consumeForExchangePayment(
        CommercePostSaleExchangeExecution $execution,
        int $paymentPosition,
        int $amountMinor,
        string $idempotencyKey,
        User $actor
    ): CustomerCreditConsumption {
        return DB::transaction(function () use (
            $execution,
            $paymentPosition,
            $amountMinor,
            $idempotencyKey,
            $actor
        ): CustomerCreditConsumption {
            $organizationId =
                (int) $actor->current_organization_id;

            if (
                $organizationId <= 0
                || $paymentPosition <= 0
                || $amountMinor <= 0
            ) {
                throw new DomainException(
                    'El consumo de saldo a favor contiene datos inválidos.'
                );
            }

            if (
                ! DB::table('organizations')
                    ->where('id', $organizationId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'La organización no está activa.'
                );
            }

            $idempotencyKey =
                trim($idempotencyKey);

            if (
                $idempotencyKey === ''
                || mb_strlen($idempotencyKey) > 180
            ) {
                throw new DomainException(
                    'La clave idempotente del consumo de saldo a favor es inválida.'
                );
            }

            $membership =
                OrganizationMembership::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'user_id',
                        $actor->id
                    )
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

            if (
                ! $membership?->role
                    ->canExecuteCommercePostSaleExchange()
            ) {
                throw new DomainException(
                    'El usuario no puede consumir saldo a favor en la ejecución de un cambio.'
                );
            }

            $lockedExecution =
                CommercePostSaleExchangeExecution::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->whereKey($execution->id)
                    ->lockForUpdate()
                    ->first();

            if (! $lockedExecution) {
                throw new DomainException(
                    'La ejecución de cambio no pertenece a la organización activa.'
                );
            }

            $lockedExecution->loadMissing(
                'selection.resolution.request.sale'
            );

            $sale =
                $lockedExecution
                    ->selection
                    ?->resolution
                    ?->request
                    ?->sale;

            if (
                $lockedExecution
                    ->difference_amount_minor <= 0
                || ! $sale
                || $sale->status->value
                    !== 'confirmed'
                || $sale
                    ->customer_business_party_id
                    === null
                || $sale->currency_code
                    !== $lockedExecution
                        ->currency_code
            ) {
                throw new DomainException(
                    'El saldo a favor sólo puede aplicarse a una diferencia positiva de cambio con cliente identificado.'
                );
            }

            $partyId =
                (int) $sale
                    ->customer_business_party_id;
            $currencyCode =
                (string) $lockedExecution
                    ->currency_code;

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'target_kind' =>
                        'exchange_payment',
                    'target_id' =>
                        (int) $lockedExecution->id,
                    'business_party_id' =>
                        $partyId,
                    'payment_position' =>
                        $paymentPosition,
                    'currency_code' =>
                        $currencyCode,
                    'amount_minor' =>
                        $amountMinor,
                ]);

            $existing =
                CustomerCreditConsumption::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->with('allocations')
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente del saldo a favor ya representa otra operación.'
                    );
                }

                return $existing;
            }

            $standard =
                CustomerCreditGrant::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->orderBy('granted_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $exchange =
                CommercePostSaleExchangeCreditGrant::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->orderBy('granted_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $advances =
                CustomerAdvance::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'business_party_id',
                        $partyId
                    )
                    ->where(
                        'currency_code',
                        $currencyCode
                    )
                    ->where(
                        'status',
                        CustomerAdvanceStatus::Confirmed->value
                    )
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $sources =
                collect()
                    ->concat(
                        $standard->map(
                            fn (
                                CustomerCreditGrant $grant
                            ): array => [
                                'kind' =>
                                    'customer_credit',
                                'id' =>
                                    (int) $grant->id,
                                'amount_minor' =>
                                    (int) $grant
                                        ->amount_minor,
                                'granted_at' =>
                                    $grant
                                        ->granted_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->concat(
                        $exchange->map(
                            fn (
                                CommercePostSaleExchangeCreditGrant $grant
                            ): array => [
                                'kind' =>
                                    'exchange_credit',
                                'id' =>
                                    (int) $grant->id,
                                'amount_minor' =>
                                    (int) $grant
                                        ->amount_minor,
                                'granted_at' =>
                                    $grant
                                        ->granted_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->concat(
                        $advances->map(
                            fn (
                                CustomerAdvance $advance
                            ): array => [
                                'kind' =>
                                    'customer_advance',
                                'id' =>
                                    (int) $advance->id,
                                'amount_minor' =>
                                    (int) $advance
                                        ->amount_minor,
                                'granted_at' =>
                                    $advance
                                        ->received_at
                                        ->format(
                                            'Y-m-d H:i:s.u'
                                        ),
                            ]
                        )
                    )
                    ->sortBy(
                        fn (array $source): string =>
                            $source['granted_at']
                            .'|'
                            .$source['kind']
                            .'|'
                            .str_pad(
                                (string) $source['id'],
                                20,
                                '0',
                                STR_PAD_LEFT
                            )
                    )
                    ->values();

            $standardConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'customer_credit_grant_id',
                        $standard->pluck('id')
                    )
                    ->selectRaw(
                        'customer_credit_grant_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'customer_credit_grant_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'customer_credit_grant_id'
                    );

            $exchangeConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'commerce_post_sale_exchange_credit_grant_id',
                        $exchange->pluck('id')
                    )
                    ->selectRaw(
                        'commerce_post_sale_exchange_credit_grant_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'commerce_post_sale_exchange_credit_grant_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'commerce_post_sale_exchange_credit_grant_id'
                    );

            $advanceConsumed =
                CustomerCreditConsumptionAllocation::query()
                    ->whereIn(
                        'customer_advance_id',
                        $advances->pluck('id')
                    )
                    ->selectRaw(
                        'customer_advance_id, SUM(amount_minor) AS allocated_minor'
                    )
                    ->groupBy(
                        'customer_advance_id'
                    )
                    ->pluck(
                        'allocated_minor',
                        'customer_advance_id'
                    );

            $plan = [];
            $remaining = $amountMinor;

            foreach ($sources as $source) {
                $already = match (
                    $source['kind']
                ) {
                    'customer_credit' =>
                        (int) (
                            $standardConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    'exchange_credit' =>
                        (int) (
                            $exchangeConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    'customer_advance' =>
                        (int) (
                            $advanceConsumed[
                                $source['id']
                            ] ?? 0
                        ),
                    default => throw new DomainException(
                        'La fuente de saldo a favor no es válida.'
                    ),
                };

                $available =
                    max(
                        0,
                        $source['amount_minor']
                        - $already
                    );

                if ($available === 0) {
                    continue;
                }

                $take =
                    min($available, $remaining);

                $plan[] = [
                    'kind' =>
                        $source['kind'],
                    'id' =>
                        $source['id'],
                    'amount_minor' =>
                        $take,
                ];

                $remaining -= $take;

                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining !== 0) {
                throw new DomainException(
                    'El cliente no posee saldo a favor suficiente para este importe.'
                );
            }

            $consumption =
                CustomerCreditConsumption::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'public_id' =>
                            (string) Str::uuid(),
                        'business_party_id' =>
                            $partyId,
                        'commerce_sale_id' =>
                            $sale->id,
                        'target_kind' =>
                            'exchange_payment',
                        'target_id' =>
                            $lockedExecution->id,
                        'commerce_post_sale_exchange_execution_id' =>
                            $lockedExecution->id,
                        'payment_position' =>
                            $paymentPosition,
                        'currency_code' =>
                            $currencyCode,
                        'amount_minor' =>
                            $amountMinor,
                        'consumed_by_user_id' =>
                            $actor->id,
                        'consumed_at' =>
                            CarbonImmutable::now(),
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'created_at' =>
                            CarbonImmutable::now(),
                    ]);

            foreach ($plan as $index => $item) {
                CustomerCreditConsumptionAllocation::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'customer_credit_consumption_id' =>
                            $consumption->id,
                        'sequence' =>
                            $index + 1,
                        'customer_credit_grant_id' =>
                            $item['kind']
                                === 'customer_credit'
                                ? $item['id']
                                : null,
                        'commerce_post_sale_exchange_credit_grant_id' =>
                            $item['kind']
                                === 'exchange_credit'
                                ? $item['id']
                                : null,
                        'customer_advance_id' =>
                            $item['kind']
                                === 'customer_advance'
                                ? $item['id']
                                : null,
                        'amount_minor' =>
                            $item['amount_minor'],
                        'fingerprint' =>
                            $this->fingerprint([
                                'consumption_id' =>
                                    (int) $consumption
                                        ->id,
                                'sequence' =>
                                    $index + 1,
                                'kind' =>
                                    $item['kind'],
                                'source_id' =>
                                    $item['id'],
                                'amount_minor' =>
                                    $item[
                                        'amount_minor'
                                    ],
                            ]),
                        'created_at' =>
                            CarbonImmutable::now(),
                    ]);
            }

            return $consumption
                ->refresh()
                ->load('allocations');
        }, 3);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fingerprint(
        array $payload
    ): string {
        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No fue posible identificar el consumo de saldo a favor.',
                previous: $exception
            );
        }
    }
}