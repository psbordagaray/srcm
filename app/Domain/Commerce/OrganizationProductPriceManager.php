<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\OrganizationProductPrice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class OrganizationProductPriceManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function set(
        CatalogProduct $product,
        string $currencyCode,
        int $amountMinor,
        ?string $reason,
        User $actor
    ): OrganizationProductPrice {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageCommercePrices() ?? false)) {
            throw new DomainException(
                'No posee permiso para modificar precios comerciales.'
            );
        }

        $currency = strtoupper(trim($currencyCode));

        if (! in_array($currency, ['ARS', 'USD'], true)) {
            throw new DomainException(
                'La moneda del precio debe ser ARS o USD.'
            );
        }

        if ($amountMinor <= 0) {
            throw new DomainException(
                'El precio comercial debe ser mayor que cero.'
            );
        }

        $reason = filled($reason)
            ? trim((string) $reason)
            : null;

        return DB::transaction(function () use (
            $organizationId,
            $product,
            $currency,
            $amountMinor,
            $reason,
            $actor
        ): OrganizationProductPrice {
            Organization::query()
                ->whereKey($organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $current = OrganizationProductPrice::query()
                ->forOrganization($organizationId)
                ->where(
                    'catalog_product_id',
                    $product->getKey()
                )
                ->where('currency_code', $currency)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if (
                $current
                && (int) $current->amount_minor === $amountMinor
            ) {
                return $current;
            }

            $now = now();

            if ($current) {
                $current->forceFill([
                    'valid_until' => $now,
                    'is_current' => null,
                ])->save();
            }

            $price = OrganizationProductPrice::query()->create([
                'organization_id' => $organizationId,
                'catalog_product_id' => $product->getKey(),
                'currency_code' => $currency,
                'amount_minor' => $amountMinor,
                'valid_from' => $now,
                'valid_until' => null,
                'is_current' => true,
                'reason' => $reason,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->audit->record(
                $price,
                'organization_product_price_changed',
                $current
                    ? [
                        'currency_code' => $current->currency_code,
                        'amount_minor' => (int) $current->amount_minor,
                        'valid_from' => $current->valid_from,
                    ]
                    : null,
                [
                    'catalog_product_id' => (int) $product->getKey(),
                    'currency_code' => $currency,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                ]
            );

            return $price->refresh();
        }, 3);
    }
}
