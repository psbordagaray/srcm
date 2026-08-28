<?php

namespace App\Domain\Device;

use App\Domain\Commerce\OrganizationProductPriceReader;
use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Enums\OperationalDeviceCapability;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

final class OperationalDeviceReadModelSnapshotBuilder
{
    public const SNAPSHOT_VERSION = 2;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly OrganizationProductPriceReader $priceReader,
        private readonly InventoryAvailabilityReader $availability
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        User $actor,
        OperationalDeviceBrowserBinding $binding
    ): array {
        $organization = $this->currentOrganization->get($actor);
        $device = $binding->device;

        if (
            ! $device
            || ! $device->active
            || (int) $device->organization_id
                !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'El binding operativo no pertenece a un dispositivo activo de la organización.'
            );
        }

        $hasReadModelCapability = $device->capabilityGrants
            ->contains(
                fn ($grant): bool =>
                    $grant->capability
                        === OperationalDeviceCapability::RestrictedOfflineReadModel
            );

        if (! $hasReadModelCapability) {
            throw new DomainException(
                'El dispositivo no posee la capacidad de read-model offline restringido.'
            );
        }

        $organizationId = (int) $organization->getKey();

        $products = CatalogProduct::query()
            ->where('active', true)
            ->with([
                'productCategory',
                'brand',
                'manufacturer',
                'knowledgeEntity.identifiers.identifierType',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'product_category_id',
                'brand_id',
                'manufacturer_id',
                'knowledge_entity_id',
                'knowledge_identifier_id',
                'sku',
                'name',
                'base_unit_code',
                'quantity_scale',
            ]);

        $productIds = $products
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $catalog = $products
            ->map(
                fn (CatalogProduct $product): array => [
                    'id' => (int) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->base_unit_code,
                    'scale' => (int) $product->quantity_scale,
                    'category' => $product->productCategory?->name,
                    'brand' => $product->brand?->name,
                    'manufacturer' => $product->manufacturer?->name,
                    'search_terms' => $this->searchTerms($product),
                ]
            )
            ->values()
            ->all();

        $locations = InventoryLocation::query()
            ->forOrganization($organizationId)
            ->active()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(
                fn (InventoryLocation $location): array => [
                    'id' => (int) $location->id,
                    'name' => $location->name,
                ]
            )
            ->values()
            ->all();

        $conditions = collect(InventoryCondition::cases())
            ->map(
                fn (InventoryCondition $condition): array => [
                    'value' => $condition->value,
                    'label' => $condition->label(),
                ]
            )
            ->values()
            ->all();

        $prices = $productIds === []
            ? []
            : $this->priceReader
                ->currentForProducts($organizationId, $productIds)
                ->map(
                    fn ($price): array => [
                        'product_id' => (int) $price->catalog_product_id,
                        'currency' => strtoupper(
                            (string) $price->currency_code
                        ),
                        'amount_minor' => (int) $price->amount_minor,
                        'valid_from' => $price->valid_from?->toAtomString(),
                        'valid_until' => $price->valid_until?->toAtomString(),
                    ]
                )
                ->values()
                ->all();

        $availability = $this->availability
            ->positions($actor)
            ->filter(
                fn ($position): bool =>
                    $position->productActive
                    && $position->locationActive
            )
            ->sortBy(
                fn ($position): string =>
                    sprintf(
                        '%020d:%020d:%s',
                        $position->catalogProductId,
                        $position->inventoryLocationId,
                        $position->condition->value
                    )
            )
            ->map(
                fn ($position): array => [
                    'product_id' => $position->catalogProductId,
                    'location_id' => $position->inventoryLocationId,
                    'condition' => $position->condition->value,
                    'available_quantity' => $position->availableQuantity,
                    'balance_version' => $position->balanceVersion,
                ]
            )
            ->values()
            ->all();

        $content = [
            'device' => [
                'public_id' => $device->public_id,
                'label' => $device->label,
            ],
            'catalog' => $catalog,
            'locations' => $locations,
            'conditions' => $conditions,
            'prices' => $prices,
            'availability' => $availability,
            'policy' => [
                'server_authoritative_at_confirmation' => true,
                'price_revalidation_required_at_confirmation' => true,
                'availability_revalidation_required_at_confirmation' => true,
                'offline_final_sale_allowed' => false,
                'offline_payment_finalization_allowed' => false,
                'offline_fiscal_authorization_allowed' => false,
                'silent_price_or_stock_conflict_merge_allowed' => false,
                'contains_customer_data' => false,
                'contains_customer_credit_data' => false,
                'contains_cash_session_data' => false,
                'contains_financial_account_data' => false,
                'contains_payment_data' => false,
                'contains_fiscal_credentials' => false,
            ],
        ];

        $scope = [
            'binding_public_id' => (string) $binding->public_id,
            'device_public_id' => (string) $device->public_id,
            'binding_expires_at' => $binding->expires_at->toAtomString(),
        ];

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'generated_at' => CarbonImmutable::now()->toAtomString(),
            'scope' => $scope,
            'content_fingerprint' => $this->fingerprint($content),
            ...$content,
        ];
    }

    /**
     * @return list<array{value: string, kind: string, exact: bool}>
     */
    private function searchTerms(CatalogProduct $product): array
    {
        $terms = collect();

        $push = function (
            mixed $value,
            string $kind,
            bool $exact = false
        ) use ($terms): void {
            $text = Str::of((string) ($value ?? ''))
                ->squish()
                ->toString();

            if ($text === '') {
                return;
            }

            $terms->push([
                'value' => $text,
                'kind' => $kind,
                'exact' => $exact,
            ]);
        };

        $push($product->sku, 'SKU', true);
        $push($product->name, 'Artículo');
        $push($product->productCategory?->name, 'Categoría');
        $push($product->brand?->name, 'Marca');
        $push($product->manufacturer?->name, 'Fabricante');

        $knowledgeEntity = $product->knowledgeEntity;

        if ($knowledgeEntity?->active) {
            $push(
                $knowledgeEntity->name,
                'Ficha de conocimiento'
            );

            foreach (
                $knowledgeEntity->identifiers
                    ->where('active', true)
                as $identifier
            ) {
                $push(
                    $identifier->value,
                    $identifier->identifierType?->name
                        ?? 'Identificador',
                    true
                );
            }
        }

        return $terms
            ->unique(
                fn (array $term): string =>
                    Str::lower(
                        $term['kind'].'|'.$term['value']
                    )
            )
            ->sortBy(
                fn (array $term): string =>
                    Str::lower(
                        $term['kind'].'|'.$term['value']
                    )
            )
            ->values()
            ->all();
    }

    private function fingerprint(array $content): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($content),
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            )
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->canonicalize($item),
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
