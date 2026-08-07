<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Http\Requests\StorePurchaseReceiptRequest;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PurchaseReceiptController extends Controller
{
    public function create(
        Request $request,
        string $purchaseOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );
        abort_unless($order->status->acceptsReceipts(), 404);
        $order->load([
            'supplier.party',
            'lines.product:id,sku,name,base_unit_code,quantity_scale',
            'lines.receiptLines',
        ]);

        return view('purchases.receipts.create', [
            'order' => $order,
            'lineBalances' => $this->lineBalances($order),
            'locations' => InventoryLocation::query()
                ->forOrganization((int) $order->organization_id)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'conditions' => InventoryCondition::cases(),
            'idempotencyKey' => 'purchase-ui:receipt:'.Str::uuid(),
        ]);
    }

    public function store(
        StorePurchaseReceiptRequest $request,
        string $purchaseOrder,
        PurchaseReceiptManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->receive(
                new PurchaseReceiptData(
                    purchaseOrderId: $order->id,
                    receivedAt: CarbonImmutable::parse(
                        $validated['received_at'],
                        config('app.timezone')
                    ),
                    idempotencyKey: $validated['idempotency_key'],
                    lines: collect($validated['lines'])
                        ->map(fn (array $line): PurchaseReceiptLineData =>
                            new PurchaseReceiptLineData(
                                purchaseOrderLineId:
                                    $line['purchase_order_line_id'],
                                quantity: $line['quantity'],
                                inventoryLocationId:
                                    $line['inventory_location_id'],
                                condition: InventoryCondition::from(
                                    $line['condition']
                                ),
                                actualUnitCostMinor: $this->moneyMinor(
                                    $line['actual_unit_cost']
                                )
                            ))
                        ->values()
                        ->all(),
                    logisticsCostMinor: $this->moneyMinor(
                        $validated['logistics_cost']
                    ),
                    documentReference:
                        $validated['document_reference'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['receipt' => $exception->getMessage()]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with(
                'success',
                'Recepción confirmada e inventario actualizado atómicamente.'
            );
    }

    private function scopedOrder(
        Request $request,
        string $publicId,
        CurrentOrganization $currentOrganization
    ): PurchaseOrder {
        $organizationId = $currentOrganization->id($request->user());

        return PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return array<int, array{received: string, remaining: string}> */
    private function lineBalances(PurchaseOrder $order): array
    {
        $balances = [];

        foreach ($order->lines as $line) {
            $received = InventoryQuantity::signed('0');

            foreach ($line->receiptLines as $receiptLine) {
                $received = InventoryQuantity::add(
                    $received,
                    $receiptLine->received_quantity
                );
            }

            $balances[(int) $line->id] = [
                'received' => $received,
                'remaining' => InventoryQuantity::subtract(
                    $line->ordered_quantity,
                    $received
                ),
            ];
        }

        return $balances;
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of($value)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
