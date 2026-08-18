<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchaseObligationBalanceReader;
use App\Domain\Purchase\PurchasePaymentControlReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\FinancialAccount;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $search = Str::of((string) $request->query('search'))
            ->squish()
            ->limit(100, '')
            ->toString();
        $status = PurchaseOrderStatus::tryFrom(
            (string) $request->query('status')
        )?->value ?? '';
        $supplierId = ctype_digit((string) $request->query('supplier'))
            ? (int) $request->query('supplier')
            : null;

        if (
            $supplierId !== null
            && ! Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($supplierId)
                ->exists()
        ) {
            $supplierId = null;
        }

        $orders = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party',
                'receipts:id,purchase_order_id,document_reference,received_at',
            ])
            ->when($status !== '', fn (Builder $query) => $query
                ->where('status', $status))
            ->when($supplierId !== null, fn (Builder $query) => $query
                ->where('supplier_id', $supplierId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $match) use ($search): void {
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
                            fn (Builder $receipt): Builder => $receipt
                                ->where(
                                    'document_reference',
                                    'like',
                                    "%{$search}%"
                                )
                        );
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('purchases.index', [
            'orders' => $orders,
            'search' => $search,
            'status' => $status,
            'supplierId' => $supplierId,
            'statuses' => PurchaseOrderStatus::cases(),
            'suppliers' => $this->suppliers($organizationId, false),
            'summary' => [
                'draft' => $this->statusCount(
                    $organizationId,
                    PurchaseOrderStatus::Draft
                ),
                'open' => PurchaseOrder::query()
                    ->forOrganization($organizationId)
                    ->whereIn('status', [
                        PurchaseOrderStatus::Issued->value,
                        PurchaseOrderStatus::PartiallyReceived->value,
                    ])
                    ->count(),
                'received' => $this->statusCount(
                    $organizationId,
                    PurchaseOrderStatus::Received
                ),
                'cancelled' => $this->statusCount(
                    $organizationId,
                    PurchaseOrderStatus::Cancelled
                ),
            ],
        ]);
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        return view('purchases.create', [
            ...$this->formOptions($organizationId),
            'idempotencyKey' => 'purchase-ui:order:'.Str::uuid(),
        ]);
    }

    public function store(
        SavePurchaseOrderRequest $request,
        PurchaseOrderManager $manager
    ): RedirectResponse {
        try {
            $order = $manager->draft(
                $this->orderData($request->validated()),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['purchase' => $exception->getMessage()]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Orden de compra guardada como borrador.');
    }

    public function show(
        Request $request,
        string $purchaseOrder,
        CurrentOrganization $currentOrganization,
        PurchasePaymentControlReader $paymentControl,
        PurchaseObligationBalanceReader $obligationBalance
    ): View {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );
        $order->load([
            'supplier.party',
            'createdBy:id,name',
            'issuedBy:id,name',
            'cancelledBy:id,name',
            'lines.product:id,sku,name,base_unit_code,quantity_scale',
            'lines.supplierOffer:id,supplier_code,published_description',
            'lines.receiptLines.inventoryLocation:id,name',
            'receipts.receivedBy:id,name',
            'receipts.inventoryMovement:id,public_id,type,status',
            'receipts.lines.product:id,sku,name,base_unit_code,quantity_scale',
            'receipts.lines.inventoryLocation:id,name',
            'receipts.obligations.beneficiary:id,name,tax_id',
            'receipts.obligations.recognizedBy:id,name',
            'receipts.obligations.paymentRequests.originFinancialAccount:id,name,type,currency_code,active',
            'receipts.obligations.paymentRequests.requestedBy:id,name',
            'receipts.obligations.paymentRequests.approvedBy:id,name',
            'receipts.obligations.paymentRequests.resolvedBy:id,name',
            'receipts.obligations.paymentRequests.execution.executedBy:id,name',
            'receipts.obligations.paymentRequests.execution.cashMovement:id,public_id,purchase_payment_execution_id,direction,type,amount_minor,currency_code,occurred_at',
            'receipts.obligations.paymentRequests.disbursement.executedBy:id,name',
            'receipts.obligations.paymentRequests.disbursement.originFinancialAccount:id,name,type,currency_code,active',
            'receipts.obligations.paymentRequests.disbursement.cashMovement',
            'receipts.obligations.paymentRequests.disbursement.cashRegisterSession.closure',
            'receipts.obligations.paymentRequests.disbursement.cashRegister',
            'receipts.obligations.paymentRequests.disbursement.allocations',
            'receipts.obligations.paymentGroupItems.request:id,public_id,status',
        ]);

        $paymentControls = collect();
        $disbursementControls = collect();
        $obligationBalances = collect();

        foreach ($order->receipts as $receipt) {
            foreach ($receipt->obligations as $obligation) {
                $obligationBalances->put(
                    $obligation->id,
                    $obligationBalance->read($obligation)
                );

                foreach ($obligation->paymentRequests as $paymentRequest) {
                    if ($paymentRequest->execution) {
                        $paymentControls->put(
                            $paymentRequest->execution->id,
                            $paymentControl->read(
                                $paymentRequest->execution,
                                $request->user()
                            )
                        );
                    }

                    if ($paymentRequest->disbursement) {
                        $disbursementControls->put(
                            $paymentRequest->disbursement->id,
                            $paymentControl->readDisbursement(
                                $paymentRequest->disbursement,
                                $request->user()
                            )
                        );
                    }
                }
            }
        }
        return view('purchases.show', [
            'order' => $order,
            'lineBalances' => $this->lineBalances($order),
            'paymentControls' => $paymentControls,
            'disbursementControls' => $disbursementControls,
            'obligationBalances' => $obligationBalances,
            'obligationBeneficiaries' =>
                BusinessParty::query()
                    ->forOrganization((int) $order->organization_id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'tax_id']),
            'paymentOrigins' =>
                FinancialAccount::query()
                    ->forOrganization((int) $order->organization_id)
                    ->where('active', true)
                    ->where(
                        'type',
                        '!=',
                        FinancialAccountType::CashReserve->value
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'type',
                        'currency_code',
                        'external_label',
                    ]),
        ]);
    }

    public function edit(
        Request $request,
        string $purchaseOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );
        abort_unless($order->status === PurchaseOrderStatus::Draft, 404);
        $order->load('lines');

        return view('purchases.edit', [
            'order' => $order,
            ...$this->formOptions((int) $order->organization_id),
            'idempotencyKey' => $order->idempotency_key,
        ]);
    }

    public function update(
        SavePurchaseOrderRequest $request,
        string $purchaseOrder,
        PurchaseOrderManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );

        try {
            $order = $manager->revise(
                $order,
                $this->orderData($request->validated()),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['purchase' => $exception->getMessage()]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Borrador de compra actualizado.');
    }

    public function issue(
        Request $request,
        string $purchaseOrder,
        PurchaseOrderManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );

        try {
            $order = $manager->issue($order, $request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Orden de compra emitida e inmovilizada.');
    }

    public function cancel(
        CancelPurchaseOrderRequest $request,
        string $purchaseOrder,
        PurchaseOrderManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $order = $this->scopedOrder(
            $request,
            $purchaseOrder,
            $currentOrganization
        );

        try {
            $order = $manager->cancel(
                $order,
                $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['purchase' => $exception->getMessage()]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Orden de compra cancelada con motivo registrado.');
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

    /** @return array<string, mixed> */
    private function formOptions(int $organizationId): array
    {
        return [
            'suppliers' => $this->suppliers($organizationId, true),
            'products' => CatalogProduct::query()
                ->where('active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'sku',
                    'name',
                    'base_unit_code',
                    'quantity_scale',
                ]),
            'offers' => SupplierOffer::query()
                ->forOrganization($organizationId)
                ->where('active', true)
                ->with([
                    'supplier.party:id,name',
                    'product:id,sku,name',
                ])
                ->orderBy('supplier_id')
                ->orderBy('catalog_product_id')
                ->get(),
        ];
    }

    /** @return Collection<int, Supplier> */
    private function suppliers(
        int $organizationId,
        bool $activeOnly
    ): Collection {
        return Supplier::query()
            ->forOrganization($organizationId)
            ->with('party')
            ->when($activeOnly, fn (Builder $query) => $query
                ->where('active', true))
            ->get()
            ->sortBy(fn (Supplier $supplier): string =>
                Str::lower((string) $supplier->party?->name))
            ->values();
    }

    private function statusCount(
        int $organizationId,
        PurchaseOrderStatus $status
    ): int {
        return PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('status', $status->value)
            ->count();
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

    /** @param array<string, mixed> $validated */
    private function orderData(array $validated): PurchaseOrderDraftData
    {
        return new PurchaseOrderDraftData(
            supplierId: $validated['supplier_id'],
            currencyCode: $validated['currency_code'],
            idempotencyKey: $validated['idempotency_key'],
            lines: collect($validated['lines'])
                ->map(fn (array $line): PurchaseOrderLineData =>
                    new PurchaseOrderLineData(
                        catalogProductId: $line['catalog_product_id'],
                        quantity: $line['quantity'],
                        unitCostMinor: $this->moneyMinor(
                            $line['unit_cost']
                        ),
                        supplierOfferId:
                            $line['supplier_offer_id'] ?? null,
                        supplierCode: $line['supplier_code'] ?? null,
                        description: $line['description'] ?? null
                    ))
                ->values()
                ->all(),
            expectedLogisticsCostMinor: $this->moneyMinor(
                $validated['expected_logistics_cost']
            ),
            notes: $validated['notes'] ?? null
        );
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of($value)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
