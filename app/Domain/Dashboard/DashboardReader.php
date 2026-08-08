<?php

namespace App\Domain\Dashboard;

use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSaleStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ServiceOrderStatus;
use App\Models\AuditLog;
use App\Models\BusinessParty;
use App\Models\CommerceSale;
use App\Models\Customer;
use App\Models\InventoryNegativeRequest;
use App\Models\PurchaseOrder;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

final class DashboardReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly InventoryAvailabilityReader $inventoryAvailability
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(User $actor): array
    {
        $organizationId = $this->currentOrganization->id($actor);
        $positions = $this->inventoryAvailability->positions($actor);

        $availablePositions = $positions->filter(
            fn ($position): bool =>
                $position->productActive
                && $position->locationActive
                && InventoryQuantity::isPositive(
                    $position->availableQuantity
                )
        );

        $deficitPositions = $positions->filter(
            fn ($position): bool => $position->hasDeficit()
        );

        $openServiceStatuses = array_values(array_filter(
            ServiceOrderStatus::cases(),
            fn (ServiceOrderStatus $status): bool => ! in_array(
                $status,
                [
                    ServiceOrderStatus::Delivered,
                    ServiceOrderStatus::Cancelled,
                ],
                true
            )
        ));

        $pendingPurchaseStatuses = [
            PurchaseOrderStatus::Issued->value,
            PurchaseOrderStatus::PartiallyReceived->value,
        ];

        $serviceOpen = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->whereIn(
                'status',
                array_map(
                    fn (ServiceOrderStatus $status): string => $status->value,
                    $openServiceStatuses
                )
            )
            ->count();

        $readyForDelivery = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->where('status', ServiceOrderStatus::ReadyForDelivery->value)
            ->count();

        $purchasePending = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->whereIn('status', $pendingPurchaseStatuses)
            ->count();

        $purchaseDrafts = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('status', PurchaseOrderStatus::Draft->value)
            ->count();

        $dayStart = now()->startOfDay();
        $dayEnd = now()->endOfDay();

        $salesToday = CommerceSale::query()
            ->forOrganization($organizationId)
            ->where('status', CommerceSaleStatus::Confirmed->value)
            ->whereBetween('sold_at', [$dayStart, $dayEnd]);

        $salesTodayCount = (clone $salesToday)->count();

        $salesTotals = (clone $salesToday)
            ->selectRaw('currency_code, SUM(total_minor) as total_minor')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get()
            ->map(fn ($row): array => [
                'currency_code' => (string) $row->currency_code,
                'total_minor' => (int) $row->total_minor,
            ])
            ->values();

        $recentServiceOrders = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->with('customer')
            ->latest('received_at')
            ->latest('id')
            ->limit(6)
            ->get();

        $recentPurchases = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->with('supplier.party')
            ->latest('created_at')
            ->latest('id')
            ->limit(6)
            ->get();

        $recentSales = CommerceSale::query()
            ->forOrganization($organizationId)
            ->where('status', CommerceSaleStatus::Confirmed->value)
            ->with('customer')
            ->latest('sold_at')
            ->latest('id')
            ->limit(6)
            ->get();

        $recentAudit = AuditLog::query()
            ->where('organization_id', $organizationId)
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'summary' => [
                'service_open' => $serviceOpen,
                'ready_for_delivery' => $readyForDelivery,
                'purchase_pending' => $purchasePending,
                'purchase_drafts' => $purchaseDrafts,
                'sales_today_count' => $salesTodayCount,
                'available_positions' => $availablePositions->count(),
                'products_with_stock' => $availablePositions
                    ->pluck('catalogProductId')
                    ->unique()
                    ->count(),
                'deficit_positions' => $deficitPositions->count(),
                'pending_negative_requests' =>
                    InventoryNegativeRequest::query()
                        ->forOrganization($organizationId)
                        ->where(
                            'status',
                            InventoryNegativeRequestStatus::Pending->value
                        )
                        ->count(),
                'identities' => BusinessParty::query()
                    ->forOrganization($organizationId)
                    ->count(),
                'active_customers' => Customer::query()
                    ->forOrganization($organizationId)
                    ->where('active', true)
                    ->count(),
                'active_suppliers' => Supplier::query()
                    ->forOrganization($organizationId)
                    ->where('active', true)
                    ->count(),
            ],
            'salesTotals' => $salesTotals,
            'inventoryDistribution' => $this->inventoryDistribution(
                $availablePositions
            ),
            'recentServiceOrders' => $recentServiceOrders,
            'recentPurchases' => $recentPurchases,
            'recentSales' => $recentSales,
            'recentAudit' => $recentAudit,
        ];
    }

    /**
     * @param Collection<int, mixed> $positions
     * @return Collection<int, array{
     *   id: int,
     *   name: string,
     *   positions: int,
     *   products: int
     * }>
     */
    private function inventoryDistribution(
        Collection $positions
    ): Collection {
        return $positions
            ->groupBy('inventoryLocationId')
            ->map(function (Collection $locationPositions): array {
                $first = $locationPositions->first();

                return [
                    'id' => (int) $first->inventoryLocationId,
                    'name' => (string) $first->locationName,
                    'positions' => $locationPositions->count(),
                    'products' => $locationPositions
                        ->pluck('catalogProductId')
                        ->unique()
                        ->count(),
                ];
            })
            ->sortByDesc('positions')
            ->take(6)
            ->values();
    }
}
