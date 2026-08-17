<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Customer;
use App\Models\CustomerCreditPolicy;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CustomerCreditPolicyManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function setLimit(
        Customer $customer,
        string $currencyCode,
        int $limitMinor,
        string $reason,
        string $idempotencyKey,
        User $administrator
    ): CustomerCreditPolicy {
        $currencyCode = strtoupper(trim($currencyCode));
        $reason = $this->reason($reason);
        $idempotencyKey = $this->idempotency(
            $idempotencyKey
        );

        if (
            preg_match(
                '/^[A-Z]{3}$/D',
                $currencyCode
            ) !== 1
            || $limitMinor < 0
        ) {
            throw new DomainException(
                'El límite de crédito solicitado no es válido.'
            );
        }

        return DB::transaction(function () use (
            $customer,
            $currencyCode,
            $limitMinor,
            $reason,
            $idempotencyKey,
            $administrator
        ): CustomerCreditPolicy {
            $organizationId =
                $this->currentOrganization->id(
                    $administrator
                );

            $this->lockOrganization($organizationId);
            $this->guardAdministrator(
                $organizationId,
                $administrator
            );

            $lockedCustomer = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $lockedCustomer) {
                throw new DomainException(
                    'El cliente no está activo en la organización.'
                );
            }

            $fingerprint = $this->fingerprint([
                'organization_id' => $organizationId,
                'business_party_id' =>
                    (int) $lockedCustomer->business_party_id,
                'currency_code' => $currencyCode,
                'limit_minor' => $limitMinor,
                'reason' => $reason,
                'set_by_user_id' => $administrator->id,
            ]);

            $existing = CustomerCreditPolicy::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'La misma clave de política de crédito fue reutilizada con otros datos.'
                    );
                }

                return $existing;
            }

            $version = ((int) CustomerCreditPolicy::query()
                ->forOrganization($organizationId)
                ->where(
                    'business_party_id',
                    $lockedCustomer->business_party_id
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->max('version')) + 1;

            $now = CarbonImmutable::now();

            return CustomerCreditPolicy::query()->create([
                'organization_id' => $organizationId,
                'business_party_id' =>
                    $lockedCustomer->business_party_id,
                'currency_code' => $currencyCode,
                'version' => $version,
                'limit_minor' => $limitMinor,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'set_by_user_id' => $administrator->id,
                'set_at' => $now,
                'created_at' => $now,
            ])->refresh();
        }, 3);
    }

    private function guardAdministrator(
        int $organizationId,
        User $administrator
    ): void {
        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where('user_id', $administrator->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role
                ->canManageCustomerCreditPolicy()
        ) {
            throw new DomainException(
                'Sólo un Administrador puede definir límites de crédito.'
            );
        }
    }

    private function lockOrganization(
        int $organizationId
    ): void {
        if (! DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->exists()) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }

    private function reason(string $value): string
    {
        $value = Str::of($value)
            ->squish()
            ->toString();

        if (
            $value === ''
            || Str::length($value) > 2000
        ) {
            throw new DomainException(
                'La política de crédito requiere un motivo válido.'
            );
        }

        return $value;
    }

    private function idempotency(string $value): string
    {
        $value = Str::of($value)
            ->squish()
            ->toString();

        if (
            $value === ''
            || Str::length($value) > 180
        ) {
            throw new DomainException(
                'La clave de política de crédito no es válida.'
            );
        }

        return $value;
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
                'No pudo consolidarse la política de crédito.',
                previous: $exception
            );
        }
    }
}
