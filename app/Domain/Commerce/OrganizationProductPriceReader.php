<?php

namespace App\Domain\Commerce;

use App\Models\OrganizationProductPrice;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;

final class OrganizationProductPriceReader
{
    /**
     * @param list<int> $productIds
     * @return Collection<int, OrganizationProductPrice>
     */
    public function currentForProducts(
        int $organizationId,
        array $productIds = []
    ): Collection {
        return OrganizationProductPrice::query()
            ->forOrganization($organizationId)
            ->where('is_current', true)
            ->when(
                $productIds !== [],
                fn ($query) => $query->whereIn(
                    'catalog_product_id',
                    $productIds
                )
            )
            ->orderBy('catalog_product_id')
            ->orderBy('currency_code')
            ->get();
    }

    /** @return Collection<int, OrganizationProductPrice> */
    public function currentForProduct(
        int $organizationId,
        int $productId
    ): Collection {
        return $this->currentForProducts(
            $organizationId,
            [$productId]
        );
    }

    public function priceAt(
        int $organizationId,
        int $productId,
        string $currencyCode,
        CarbonInterface $moment
    ): OrganizationProductPrice {
        $currency = strtoupper(trim($currencyCode));

        $price = OrganizationProductPrice::query()
            ->forOrganization($organizationId)
            ->where('catalog_product_id', $productId)
            ->where('currency_code', $currency)
            ->where('valid_from', '<=', $moment)
            ->where(function ($query) use ($moment): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>', $moment);
            })
            ->latest('valid_from')
            ->latest('id')
            ->first();

        if (! $price) {
            throw new DomainException(
                "El producto no posee un precio vigente en {$currency} para esta organización."
            );
        }

        return $price;
    }

    public function amountAt(
        int $organizationId,
        int $productId,
        string $currencyCode,
        CarbonInterface $moment
    ): int {
        return (int) $this->priceAt(
            $organizationId,
            $productId,
            $currencyCode,
            $moment
        )->amount_minor;
    }

    /**
     * @param Collection<int, OrganizationProductPrice> $rows
     * @return array<string, array<int, int>>
     */
    public function matrix(Collection $rows): array
    {
        $matrix = [
            'ARS' => [],
            'USD' => [],
        ];

        foreach ($rows as $row) {
            $currency = strtoupper((string) $row->currency_code);

            if (! array_key_exists($currency, $matrix)) {
                $matrix[$currency] = [];
            }

            $matrix[$currency][(int) $row->catalog_product_id] =
                (int) $row->amount_minor;
        }

        return $matrix;
    }
}
