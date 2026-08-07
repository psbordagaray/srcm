<?php

namespace App\Domain\Search;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceIdentifierType;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\PurchaseOrder;
use App\Models\ServiceOrder;
use App\Models\TechnicalModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GlobalSearchReader
{
    private const PER_GROUP = 8;

    private const QUERY_LIMIT = 100;

    private const CANDIDATE_LIMIT = 24;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @return array{
     *   query: string,
     *   ready: bool,
     *   total: int,
     *   groups: array<int, array{
     *     key: string,
     *     label: string,
     *     items: Collection<int, array<string, mixed>>
     *   }>
     * }
     */
    public function read(User $actor, ?string $rawQuery): array
    {
        $query = Str::of((string) $rawQuery)
            ->squish()
            ->limit(self::QUERY_LIMIT, '')
            ->toString();

        $needle = Str::of(
            str_replace(['%', '_'], ' ', $query)
        )->squish()->toString();

        if (Str::length($needle) < 2) {
            return $this->emptyResult($query);
        }

        $organizationId = $this->currentOrganization->id($actor);

        $groups = [
            $this->group(
                'products',
                'Productos',
                $this->products($needle)
            ),
            $this->group(
                'technical-models',
                'Modelos técnicos',
                $this->technicalModels($needle)
            ),
            $this->group(
                'business-parties',
                'Personas',
                $this->businessParties(
                    $organizationId,
                    $needle
                )
            ),
            $this->group(
                'service-orders',
                'Reparaciones',
                $this->serviceOrders(
                    $organizationId,
                    $needle
                )
            ),
            $this->group(
                'purchase-orders',
                'Compras',
                $this->purchaseOrders(
                    $organizationId,
                    $needle
                )
            ),
            $this->group(
                'commerce-sales',
                'Ventas',
                $this->commerceSales(
                    $organizationId,
                    $needle
                )
            ),
        ];

        return [
            'query' => $query,
            'ready' => true,
            'total' => collect($groups)
                ->sum(fn (array $group): int =>
                    $group['items']->count()
                ),
            'groups' => $groups,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function products(string $search): Collection
    {
        $normalized = CatalogProduct::normalizeIdentity($search);

        $products = CatalogProduct::query()
            ->with(['brand', 'productCategory'])
            ->where(function (Builder $match) use (
                $search,
                $normalized
            ): void {
                $match
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");

                if ($normalized !== '') {
                    $match
                        ->orWhere(
                            'normalized_sku',
                            'like',
                            "%{$normalized}%"
                        )
                        ->orWhere(
                            'normalized_name',
                            'like',
                            "%{$normalized}%"
                        );
                }

                $match
                    ->orWhereHas(
                        'brand',
                        fn (Builder $brand): Builder =>
                            $brand->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )
                    )
                    ->orWhereHas(
                        'productCategory',
                        fn (Builder $category): Builder =>
                            $category->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )
                    );
            })
            ->orderByDesc('active')
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $products->map(function (CatalogProduct $product) use (
                $search
            ): array {
                $parts = array_values(array_filter([
                    'SKU '.$product->sku,
                    $product->brand?->name,
                    $product->productCategory?->name,
                ]));

                return $this->item(
                    type: 'Producto',
                    title: $product->name,
                    subtitle: implode(' · ', $parts),
                    meta: $product->active ? 'Activo' : 'Inactivo',
                    url: route('products.show', $product),
                    search: $search,
                    searchValues: [
                        $product->sku,
                        $product->name,
                        $product->brand?->name,
                        $product->productCategory?->name,
                    ]
                );
            })
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function technicalModels(string $search): Collection
    {
        $models = TechnicalModel::query()
            ->with(['brand', 'productCategory'])
            ->where(function (Builder $match) use ($search): void {
                $match
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas(
                        'brand',
                        fn (Builder $brand): Builder =>
                            $brand->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )
                    )
                    ->orWhereHas(
                        'productCategory',
                        fn (Builder $category): Builder =>
                            $category->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )
                    );
            })
            ->orderByDesc('active')
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $models->map(function (TechnicalModel $model) use (
                $search
            ): array {
                $parts = array_values(array_filter([
                    filled($model->code)
                        ? 'Código '.$model->code
                        : null,
                    $model->brand?->name,
                    $model->productCategory?->name,
                ]));

                return $this->item(
                    type: 'Modelo técnico',
                    title: $model->name,
                    subtitle: implode(' · ', $parts),
                    meta: $model->active ? 'Activo' : 'Inactivo',
                    url: route('technical-models.show', $model),
                    search: $search,
                    searchValues: [
                        $model->code,
                        $model->name,
                        $model->brand?->name,
                        $model->productCategory?->name,
                    ]
                );
            })
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function businessParties(
        int $organizationId,
        string $search
    ): Collection {
        $normalizedName = BusinessParty::normalizeName($search);
        $normalizedTax = BusinessParty::normalizeTaxId($search);

        $parties = BusinessParty::query()
            ->forOrganization($organizationId)
            ->with(['customer', 'supplier'])
            ->where(function (Builder $match) use (
                $search,
                $normalizedName,
                $normalizedTax
            ): void {
                $match
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");

                if ($normalizedName !== '') {
                    $match->orWhere(
                        'normalized_name',
                        'like',
                        "%{$normalizedName}%"
                    );
                }

                if ($normalizedTax !== '') {
                    $match->orWhere(
                        'normalized_tax_id',
                        'like',
                        "%{$normalizedTax}%"
                    );
                }
            })
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $parties->map(function (BusinessParty $party) use (
                $search
            ): array {
                $roles = collect([
                    $party->customer ? 'Cliente' : null,
                    $party->supplier ? 'Proveedor' : null,
                ])->filter()->values()->join(' · ');

                $contact = collect([
                    $party->tax_id,
                    $party->email,
                    $party->phone,
                ])->filter()->first();

                return $this->item(
                    type: 'Persona',
                    title: $party->name,
                    subtitle: $contact ?: 'Sin dato de contacto',
                    meta: $roles !== '' ? $roles : 'Identidad',
                    url: route('business-parties.show', $party),
                    search: $search,
                    searchValues: [
                        $party->name,
                        $party->tax_id,
                        $party->email,
                        $party->phone,
                    ]
                );
            })
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function serviceOrders(
        int $organizationId,
        string $search
    ): Collection {
        $normalizedIdentifier = ServiceIdentifierType::Other
            ->normalize($search);

        $orders = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->with([
                'asset.identifiers',
                'customer',
                'owner',
                'intake',
            ])
            ->where(function (Builder $match) use (
                $search,
                $normalizedIdentifier
            ): void {
                if (ctype_digit($search)) {
                    $match->orWhere(
                        'order_number',
                        (int) $search
                    );
                }

                $match
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhereHas(
                        'customer',
                        fn (Builder $party): Builder => $party
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                    )
                    ->orWhereHas(
                        'owner',
                        fn (Builder $party): Builder => $party
                            ->where('name', 'like', "%{$search}%")
                    )
                    ->orWhereHas(
                        'intake',
                        fn (Builder $intake): Builder => $intake
                            ->where(
                                'customer_name_snapshot',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'owner_name_snapshot',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'customer_reported_issue',
                                'like',
                                "%{$search}%"
                            )
                    )
                    ->orWhereHas(
                        'asset',
                        fn (Builder $asset): Builder => $asset
                            ->where(
                                'brand_name',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'model_name',
                                'like',
                                "%{$search}%"
                            )
                    );

                if ($normalizedIdentifier !== '') {
                    $match->orWhereHas(
                        'asset.identifiers',
                        fn (Builder $identifier): Builder =>
                            $identifier->where(
                                'normalized_value',
                                'like',
                                "%{$normalizedIdentifier}%"
                            )
                    );
                }
            })
            ->latest('received_at')
            ->latest('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $orders->map(function (ServiceOrder $order) use (
                $search
            ): array {
                $asset = trim(
                    ($order->asset?->brand_name ?? '')
                    .' '
                    .($order->asset?->model_name ?? '')
                );
                $customer = $order->customer?->name
                    ?? $order->intake?->customer_name_snapshot;

                return $this->item(
                    type: 'Reparación',
                    title: 'Orden #'.$order->order_number,
                    subtitle: collect([$customer, $asset])
                        ->filter()
                        ->join(' · '),
                    meta: $this->enumLabel($order->status->value),
                    url: route('service-orders.show', $order),
                    search: $search,
                    searchValues: [
                        (string) $order->order_number,
                        $order->public_id,
                        $customer,
                        $asset,
                        ...$order->asset?->identifiers
                            ->pluck('value')
                            ->all() ?? [],
                    ]
                );
            })
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseOrders(
        int $organizationId,
        string $search
    ): Collection {
        $orders = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party',
                'receipts:id,purchase_order_id,document_reference,received_at',
            ])
            ->where(function (Builder $match) use ($search): void {
                $match
                    ->where('public_id', 'like', "%{$search}%")
                    ->orWhereHas(
                        'supplier.party',
                        fn (Builder $party): Builder => $party
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('tax_id', 'like', "%{$search}%")
                    )
                    ->orWhereHas(
                        'receipts',
                        fn (Builder $receipt): Builder =>
                            $receipt->where(
                                'document_reference',
                                'like',
                                "%{$search}%"
                            )
                    );
            })
            ->latest('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $orders->map(function (PurchaseOrder $order) use (
                $search
            ): array {
                $supplier = $order->supplier?->party?->name
                    ?? 'Proveedor no disponible';

                return $this->item(
                    type: 'Compra',
                    title: 'Compra · '.$supplier,
                    subtitle: Str::limit(
                        (string) $order->public_id,
                        24,
                        '…'
                    ),
                    meta: $this->enumLabel($order->status->value)
                        .' · '
                        .$order->currency_code,
                    url: route('purchase-orders.show', $order),
                    search: $search,
                    searchValues: [
                        $order->public_id,
                        $supplier,
                        $order->supplier?->party?->tax_id,
                        ...$order->receipts
                            ->pluck('document_reference')
                            ->filter()
                            ->all(),
                    ]
                );
            })
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function commerceSales(
        int $organizationId,
        string $search
    ): Collection {
        $sales = CommerceSale::query()
            ->forOrganization($organizationId)
            ->with(['customer', 'serviceOrder.asset'])
            ->where(function (Builder $match) use ($search): void {
                if (ctype_digit($search)) {
                    $match->orWhere(
                        'sale_number',
                        (int) $search
                    );
                }

                $match
                    ->orWhere(
                        'customer_name_snapshot',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'customer_document_snapshot',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhereHas(
                        'serviceOrder',
                        function (Builder $order) use (
                            $search
                        ): void {
                            if (ctype_digit($search)) {
                                $order->where(
                                    'order_number',
                                    (int) $search
                                );

                                return;
                            }

                            $order
                                ->where(
                                    'public_id',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'asset',
                                    fn (Builder $asset): Builder =>
                                        $asset
                                            ->where(
                                                'brand_name',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'model_name',
                                                'like',
                                                "%{$search}%"
                                            )
                                );
                        }
                    );
            })
            ->latest('sold_at')
            ->latest('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $this->finish(
            $sales->map(function (CommerceSale $sale) use (
                $search
            ): array {
                $customer = $sale->customer?->name
                    ?? $sale->customer_name_snapshot
                    ?? 'Consumidor sin identificar';

                return $this->item(
                    type: 'Venta',
                    title: 'Venta #'.$sale->sale_number,
                    subtitle: $customer,
                    meta: $this->enumLabel($sale->status->value)
                        .' · '
                        .$this->formatMoney(
                            $sale->total_minor,
                            $sale->currency_code
                        ),
                    url: route('commerce-sales.show', $sale),
                    search: $search,
                    searchValues: [
                        (string) $sale->sale_number,
                        $sale->public_id,
                        $customer,
                        $sale->customer_document_snapshot,
                        $sale->serviceOrder?->public_id,
                        $sale->serviceOrder?->asset?->brand_name,
                        $sale->serviceOrder?->asset?->model_name,
                    ]
                );
            })
        );
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return Collection<int, array<string, mixed>>
     */
    private function finish(Collection $items): Collection
    {
        return $items
            ->sortBy(fn (array $item): string =>
                str_pad(
                    (string) $item['_score'],
                    2,
                    '0',
                    STR_PAD_LEFT
                )
                .'|'
                .Str::lower((string) $item['title'])
            )
            ->take(self::PER_GROUP)
            ->values()
            ->map(function (array $item): array {
                unset($item['_score']);

                return $item;
            });
    }

    /**
     * @param array<int, mixed> $searchValues
     * @return array<string, mixed>
     */
    private function item(
        string $type,
        string $title,
        string $subtitle,
        string $meta,
        string $url,
        string $search,
        array $searchValues
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'url' => $url,
            '_score' => $this->score($search, $searchValues),
        ];
    }

    /**
     * @param array<int, mixed> $values
     */
    private function score(string $search, array $values): int
    {
        $needle = $this->normalize($search);

        if ($needle === '') {
            return 30;
        }

        $best = 30;

        foreach ($values as $value) {
            if (blank($value)) {
                continue;
            }

            $candidate = $this->normalize((string) $value);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $needle) {
                return 0;
            }

            if (str_starts_with($candidate, $needle)) {
                $best = min($best, 10);

                continue;
            }

            if (str_contains($candidate, $needle)) {
                $best = min($best, 20);
            }
        }

        return $best;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    private function enumLabel(string $value): string
    {
        return Str::of($value)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    private function formatMoney(
        int $minor,
        string $currency
    ): string {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $whole = intdiv($absolute, 100);
        $cents = $absolute % 100;

        return ($negative ? '-' : '')
            .$currency.' '
            .number_format($whole, 0, ',', '.')
            .','
            .str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return array{
     *   key: string,
     *   label: string,
     *   items: Collection<int, array<string, mixed>>
     * }
     */
    private function group(
        string $key,
        string $label,
        Collection $items
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   query: string,
     *   ready: bool,
     *   total: int,
     *   groups: array<int, array{
     *     key: string,
     *     label: string,
     *     items: Collection<int, array<string, mixed>>
     *   }>
     * }
     */
    private function emptyResult(string $query): array
    {
        $labels = [
            'products' => 'Productos',
            'technical-models' => 'Modelos técnicos',
            'business-parties' => 'Personas',
            'service-orders' => 'Reparaciones',
            'purchase-orders' => 'Compras',
            'commerce-sales' => 'Ventas',
        ];

        return [
            'query' => $query,
            'ready' => false,
            'total' => 0,
            'groups' => collect($labels)
                ->map(
                    fn (string $label, string $key): array =>
                        $this->group(
                            $key,
                            $label,
                            collect()
                        )
                )
                ->values()
                ->all(),
        ];
    }
}
