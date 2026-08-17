<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\SupplierCreditNoteData;
use App\Domain\Purchase\SupplierCreditNoteManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreSupplierCreditNoteRequest;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class SupplierCreditNoteController extends Controller
{
    public function store(
        StoreSupplierCreditNoteRequest $request,
        string $purchaseOrder,
        string $supplierInvoice,
        CurrentOrganization $currentOrganization,
        SupplierCreditNoteManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $order = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('public_id', $purchaseOrder)
            ->firstOrFail();

        $invoice = SupplierInvoice::query()
            ->forOrganization($organizationId)
            ->where('public_id', $supplierInvoice)
            ->where(
                'purchase_order_id',
                $order->id
            )
            ->firstOrFail();

        $validated = $request->validated();

        try {
            $manager->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId: $invoice->id,
                    documentNumber:
                        $validated['document_number'],
                    issuedOn:
                        $validated['issued_on'],
                    amountMinor:
                        $this->moneyMinor(
                            $validated['amount']
                        ),
                    reason:
                        $validated['reason'],
                    idempotencyKey:
                        $validated['idempotency_key'],
                    notes:
                        $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'supplier_credit_note' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'purchase-orders.show',
                $order
            )
            ->with(
                'success',
                'Nota de crédito del proveedor registrada como evidencia. Todavía no aplica saldo ni ejecuta pago.'
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
