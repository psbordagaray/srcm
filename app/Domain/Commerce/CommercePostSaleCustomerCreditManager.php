<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleStatus;
use App\Models\BusinessParty;
use App\Models\CommercePostSaleResolution;
use App\Models\CommercePostSaleResolutionLine;
use App\Models\CustomerCreditGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CommercePostSaleCustomerCreditManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function grant(
        CommercePostSaleResolution $resolution,
        string $idempotencyKey,
        User $actor
    ): CustomerCreditGrant {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canMaterializeCommercePostSaleCustomerCredit()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para materializar un saldo a favor de posventa.'
            );
        }

        $idempotencyKey =
            trim($idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente del saldo a favor no es válida.'
            );
        }

        return DB::transaction(function () use (
            $resolution,
            $idempotencyKey,
            $actor,
            $organizationId
        ): CustomerCreditGrant {
            $lockedResolution =
                CommercePostSaleResolution::query()
                    ->forOrganization($organizationId)
                    ->whereKey($resolution->id)
                    ->lockForUpdate()
                    ->first();

            if (! $lockedResolution) {
                throw new DomainException(
                    'La resolución de posventa no pertenece a la organización activa.'
                );
            }

            if (
                $lockedResolution->outcome
                    !== CommercePostSaleResolutionOutcome::CustomerCredit
            ) {
                throw new DomainException(
                    'Sólo una resolución de saldo a favor puede materializar crédito de cliente.'
                );
            }

            $lockedResolution->loadMissing(
                'request.sale'
            );

            $sale =
                $lockedResolution
                    ->request
                    ?->sale;

            if (
                ! $sale
                || $sale->status
                    !== CommerceSaleStatus::Confirmed
                || $sale
                    ->customer_business_party_id
                    === null
            ) {
                throw new DomainException(
                    'El saldo a favor requiere una venta confirmada con cliente identificado.'
                );
            }

            $party =
                BusinessParty::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->whereKey(
                        $sale
                            ->customer_business_party_id
                    )
                    ->lockForUpdate()
                    ->first();

            if (! $party) {
                throw new DomainException(
                    'El cliente de la venta no pertenece a la organización activa.'
                );
            }

            $lines =
                CommercePostSaleResolutionLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_post_sale_resolution_id',
                        $lockedResolution->id
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if ($lines->isEmpty()) {
                throw new DomainException(
                    'La resolución no posee valor reconocido materializable.'
                );
            }

            $amountMinor = 0;

            foreach ($lines as $line) {
                $amount = (int)
                    $line
                        ->recognized_amount_minor;

                if (
                    $amount < 0
                    || $amountMinor
                        > PHP_INT_MAX - $amount
                ) {
                    throw new DomainException(
                        'El valor reconocido supera el importe admitido.'
                    );
                }

                $amountMinor += $amount;
            }

            if ($amountMinor <= 0) {
                throw new DomainException(
                    'El saldo a favor materializado debe ser mayor que cero.'
                );
            }

            $fingerprint = hash(
                'sha256',
                implode('|', [
                    $organizationId,
                    $lockedResolution->id,
                    $party->id,
                    $lockedResolution
                        ->currency_code,
                    $amountMinor,
                    $actor->id,
                ])
            );

            $existingByResolution =
                CustomerCreditGrant::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'commerce_post_sale_resolution_id',
                        $lockedResolution->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByResolution) {
                if (
                    $existingByResolution
                        ->idempotency_key
                        !== $idempotencyKey
                    || ! hash_equals(
                        (string)
                            $existingByResolution
                                ->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La resolución ya materializó su saldo a favor con otra operación.'
                    );
                }

                return $existingByResolution
                    ->load([
                        'party',
                        'resolution.request.sale',
                        'grantedBy',
                    ]);
            }

            $existingByKey =
                CustomerCreditGrant::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                throw new DomainException(
                    'La clave idempotente del saldo a favor ya fue utilizada.'
                );
            }

            $grant =
                CustomerCreditGrant::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'business_party_id' =>
                            $party->id,
                        'commerce_post_sale_resolution_id' =>
                            $lockedResolution->id,
                        'currency_code' =>
                            $lockedResolution
                                ->currency_code,
                        'amount_minor' =>
                            $amountMinor,
                        'granted_by_user_id' =>
                            $actor->id,
                        'granted_at' =>
                            CarbonImmutable::now(),
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                    ]);

            $this->audit->record(
                $grant,
                'customer_credit_granted_from_post_sale',
                null,
                [
                    'business_party_id' =>
                        (int) $party->id,
                    'commerce_post_sale_resolution_id' =>
                        (int) $lockedResolution
                            ->id,
                    'commerce_post_sale_request_id' =>
                        (int) $lockedResolution
                            ->commerce_post_sale_request_id,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'currency_code' =>
                        $lockedResolution
                            ->currency_code,
                    'amount_minor' =>
                        $amountMinor,
                ]
            );

            return $grant->refresh()->load([
                'party',
                'resolution.request.sale',
                'grantedBy',
            ]);
        }, 3);
    }
}
