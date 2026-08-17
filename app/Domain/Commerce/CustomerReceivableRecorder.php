<?php

namespace App\Domain\Commerce;

use App\Enums\CommerceSaleStatus;
use App\Models\CommerceSale;
use App\Models\CustomerReceivable;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use JsonException;

final class CustomerReceivableRecorder
{
    public function recordForSale(
        CommerceSale $sale,
        int $amountMinor,
        ?string $dueOn,
        User $actor
    ): CustomerReceivable {
        $organizationId = (int) $sale->organization_id;

        $lockedSale = CommerceSale::query()
            ->forOrganization($organizationId)
            ->whereKey($sale->id)
            ->lockForUpdate()
            ->first();

        if (
            ! $lockedSale
            || $lockedSale->status
                !== CommerceSaleStatus::Building
            || $lockedSale->customer_business_party_id === null
            || $amountMinor <= 0
            || $amountMinor > (int) $lockedSale->total_minor
        ) {
            throw new DomainException(
                'La venta no admite registrar ese saldo pendiente.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership?->role->canCreateCustomerReceivable()) {
            throw new DomainException(
                'Sólo un Administrador puede autorizar una venta con saldo pendiente.'
            );
        }

        $normalizedDueOn = $dueOn === null
            ? null
            : CarbonImmutable::parse($dueOn)
                ->toDateString();

        if (
            $normalizedDueOn !== null
            && CarbonImmutable::parse($normalizedDueOn)
                ->startOfDay()
                ->lt($lockedSale->sold_at->startOfDay())
        ) {
            throw new DomainException(
                'El vencimiento de la cuenta por cobrar no puede ser anterior a la venta.'
            );
        }

        $idempotencyKey = 'sale-receivable:'
            .$lockedSale->public_id;
        $fingerprint = $this->fingerprint([
            'sale_public_id' => $lockedSale->public_id,
            'business_party_id' =>
                (int) $lockedSale->customer_business_party_id,
            'currency_code' => $lockedSale->currency_code,
            'amount_minor' => $amountMinor,
            'due_on' => $normalizedDueOn,
        ]);

        $existing = CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (! hash_equals($existing->fingerprint, $fingerprint)) {
                throw new DomainException(
                    'La cuenta por cobrar ya fue registrada con otros datos.'
                );
            }

            return $existing;
        }

        if (CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->where('commerce_sale_id', $lockedSale->id)
            ->lockForUpdate()
            ->exists()
        ) {
            throw new DomainException(
                'La venta ya posee una cuenta por cobrar reconocida.'
            );
        }

        $now = CarbonImmutable::now();

        return CustomerReceivable::query()->create([
            'organization_id' => $organizationId,
            'business_party_id' =>
                $lockedSale->customer_business_party_id,
            'commerce_sale_id' => $lockedSale->id,
            'currency_code' => $lockedSale->currency_code,
            'amount_minor' => $amountMinor,
            'due_on' => $normalizedDueOn,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
            'recognized_by_user_id' => $actor->id,
            'recognized_at' => $now,
            'created_at' => $now,
        ])->refresh();
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
                'No se pudo consolidar la cuenta por cobrar.',
                previous: $exception
            );
        }
    }
}
