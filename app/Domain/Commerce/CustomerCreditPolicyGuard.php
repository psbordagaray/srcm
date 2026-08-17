<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CustomerCreditDecisionType;
use App\Models\BusinessParty;
use App\Models\Customer;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

final class CustomerCreditPolicyGuard
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CustomerCreditExposureReader $exposure
    ) {
    }

    public function decide(
        BusinessParty $party,
        string $currencyCode,
        int $receivableAmountMinor,
        ?string $overrideReason,
        CarbonImmutable $asOf,
        User $actor
    ): CustomerCreditDecision {
        $organizationId =
            $this->currentOrganization->id($actor);

        if (
            (int) $party->organization_id
                !== $organizationId
            || $receivableAmountMinor <= 0
        ) {
            throw new DomainException(
                'La operación de crédito no pertenece a la organización activa.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role
                ->canCreateCustomerReceivable()
        ) {
            throw new DomainException(
                'El usuario no puede registrar ventas a crédito.'
            );
        }

        $customer = Customer::query()
            ->forOrganization($organizationId)
            ->where(
                'business_party_id',
                $party->id
            )
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            throw new DomainException(
                'La venta a crédito requiere un cliente activo.'
            );
        }

        /*
         * Exposure is evaluated at the sale effective date. Normal HTTP
         * checkout fixes that date to server-now; accepting an explicit
         * date only preserves deterministic internal/historical tests.
         * The locked customer row serializes credit sales, collections
         * and policy changes for the same customer.
         */
        $snapshot = $this->exposure->snapshot(
            $customer,
            $currencyCode,
            $actor,
            $asOf
        );

        $projected = $this->sumMoney(
            $snapshot['exposure_minor'],
            $receivableAmountMinor
        );

        $hasOverdue = $snapshot['overdue_minor'] > 0;

        /*
         * Deployment-safe transition:
         * until the first policy version is configured for this
         * customer/currency, the historical P9.1 Admin-only path survives.
         * An Operator never interprets "missing policy" as unlimited credit.
         * The Admin confirmation itself remains the legacy authorization.
         * Once a policy is configured, overdue and over-limit conditions
         * require an explicit, reasoned Admin override.
         */
        if (! $snapshot['policy_configured']) {
            if (
                ! $membership->role
                    ->canOverrideCustomerCredit()
            ) {
                throw new DomainException(
                    'Crédito bloqueado: el cliente no posee un límite configurado para esta moneda. Un Administrador debe definirlo.'
                );
            }

            if (filled($overrideReason)) {
                throw new DomainException(
                    'Sin política configurada, la venta Administrador conserva el modo transitorio y no utiliza un motivo de excepción.'
                );
            }

            return new CustomerCreditDecision(
                type:
                    CustomerCreditDecisionType::LegacyAdmin,
                policy: null,
                limitMinor: null,
                exposureBeforeMinor:
                    $snapshot['exposure_minor'],
                projectedExposureMinor: $projected,
                overdueMinor:
                    $snapshot['overdue_minor'],
                oldestDaysOverdue:
                    $snapshot['oldest_days_overdue'],
                overLimit: false,
                overdue: $hasOverdue,
                snapshotFingerprint:
                    $snapshot['snapshot_fingerprint']
            );
        }

        $overLimit =
            $projected > $snapshot['limit_minor'];

        if (! $overLimit && ! $hasOverdue) {
            if (filled($overrideReason)) {
                throw new DomainException(
                    'La operación está dentro de política y no requiere una excepción administrativa.'
                );
            }

            return new CustomerCreditDecision(
                type:
                    CustomerCreditDecisionType::WithinPolicy,
                policy: $snapshot['policy'],
                limitMinor: $snapshot['limit_minor'],
                exposureBeforeMinor:
                    $snapshot['exposure_minor'],
                projectedExposureMinor: $projected,
                overdueMinor: 0,
                oldestDaysOverdue: 0,
                overLimit: false,
                overdue: false,
                snapshotFingerprint:
                    $snapshot['snapshot_fingerprint']
            );
        }

        if (
            ! $membership->role
                ->canOverrideCustomerCredit()
        ) {
            throw new DomainException(
                $this->blockedMessage(
                    $overLimit,
                    $hasOverdue,
                    $snapshot['limit_minor'],
                    $snapshot['exposure_minor'],
                    $projected,
                    $snapshot['overdue_minor']
                )
            );
        }

        return new CustomerCreditDecision(
            type:
                CustomerCreditDecisionType::AdminOverride,
            policy: $snapshot['policy'],
            limitMinor: $snapshot['limit_minor'],
            exposureBeforeMinor:
                $snapshot['exposure_minor'],
            projectedExposureMinor: $projected,
            overdueMinor:
                $snapshot['overdue_minor'],
            oldestDaysOverdue:
                $snapshot['oldest_days_overdue'],
            overLimit: $overLimit,
            overdue: $hasOverdue,
            snapshotFingerprint:
                $snapshot['snapshot_fingerprint'],
            overrideReason:
                $this->overrideReason(
                    $overrideReason
                )
        );
    }

    private function overrideReason(
        ?string $value
    ): string {
        $value = Str::of((string) $value)
            ->squish()
            ->toString();

        if (
            $value === ''
            || Str::length($value) > 2000
        ) {
            throw new DomainException(
                'La excepción de crédito requiere un motivo explícito del Administrador.'
            );
        }

        return $value;
    }

    private function blockedMessage(
        bool $overLimit,
        bool $overdue,
        int $limitMinor,
        int $exposureMinor,
        int $projectedMinor,
        int $overdueMinor
    ): string {
        $reasons = [];

        if ($overdue) {
            $reasons[] =
                'el cliente posee deuda vencida por '
                .$this->money($overdueMinor);
        }

        if ($overLimit) {
            $reasons[] =
                'la exposición proyectada '
                .$this->money($projectedMinor)
                .' supera el límite '
                .$this->money($limitMinor)
                .' (exposición actual '
                .$this->money($exposureMinor).')';
        }

        return 'Crédito bloqueado: '
            .implode(' y ', $reasons)
            .'. Requiere una excepción explícita de Administrador.';
    }

    private function money(int $minor): string
    {
        return '$ '.number_format(
            $minor / 100,
            2,
            ',',
            '.'
        );
    }

    private function sumMoney(
        int $left,
        int $right
    ): int {
        if (
            $left < 0
            || $right < 0
            || $left > PHP_INT_MAX - $right
        ) {
            throw new DomainException(
                'La exposición proyectada supera el importe admitido.'
            );
        }

        return $left + $right;
    }
}
