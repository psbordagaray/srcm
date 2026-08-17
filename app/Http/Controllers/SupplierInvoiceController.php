<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\SupplierInvoiceData;
use App\Domain\Purchase\SupplierInvoiceLineData;
use App\Domain\Purchase\SupplierInvoiceManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreSupplierInvoiceRequest;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class SupplierInvoiceController extends Controller
{
    public function store(
        StoreSupplierInvoiceRequest $request,
        string $purchaseOrder,
        CurrentOrganization $currentOrganization,
        SupplierInvoiceManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $order = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('public_id', $purchaseOrder)
            ->firstOrFail();

        $validated = $request->validated();

        try {
            $manager->record(
                new SupplierInvoiceData(
                    purchaseOrderId: $order->id,
                    documentNumber:
                        $validated['document_number'],
                    issuedOn: $validated['issued_on'],
                    dueOn: $validated['due_on'] ?? null,
                    logisticsAmountMinor:
                        $this->moneyMinor(
                            $validated[
                                'logistics_amount'
                            ]
                        ),
                    lines: collect(
                        $validated['lines']
                    )->map(
                        fn (array $line):
                            SupplierInvoiceLineData =>
                                new SupplierInvoiceLineData(
                                    purchaseOrderLineId:
                                        $line[
                                            'purchase_order_line_id'
                                        ] ?? null,
                                    description:
                                        $line['description'],
                                    quantity:
                                        (string) $line['quantity'],
                                    unitCostMinor:
                                        $this->moneyMinor(
                                            $line['unit_cost']
                                        ),
                                    supplierCode:
                                        $line[
                                            'supplier_code'
                                        ] ?? null
                                )
                    )->values()->all(),
                    idempotencyKey:
                        $validated['idempotency_key'],
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'supplier_invoice' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with(
                'success',
                'Documento del proveedor registrado como evidencia. No creó obligación ni pago.'
            );
    }

    private function moneyMinor(
        int|float|string $value
    ): int {
        return (int) (string) BigDecimal::of(
            (string) $value
        )
            ->multipliedBy(100)
            ->toScale(
                0,
                RoundingMode::Unnecessary
            )
            ->toBigInteger();
    }
}
