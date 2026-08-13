<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Http\Requests\StorePurchaseObligationRequest;
use App\Models\PurchaseReceipt;
use DomainException;
use Illuminate\Http\RedirectResponse;

class PurchaseObligationController extends Controller
{
    public function store(
        StorePurchaseObligationRequest $request,
        string $purchaseOrder,
        string $purchaseReceipt,
        CurrentOrganization $currentOrganization,
        PurchaseObligationManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $receipt = PurchaseReceipt::query()
            ->forOrganization($organizationId)
            ->where('public_id', $purchaseReceipt)
            ->whereHas(
                'order',
                fn ($query) => $query->where(
                    'public_id',
                    $purchaseOrder
                )
            )
            ->firstOrFail();

        $validated = $request->validated();

        try {
            $obligation = $manager->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId: (int) $receipt->id,
                    kind: PurchaseObligationKind::from(
                        $validated['kind']
                    ),
                    beneficiaryBusinessPartyId:
                        $validated[
                            'beneficiary_business_party_id'
                        ] ?? null,
                    paymentCondition:
                        PurchaseObligationCondition::from(
                            $validated['payment_condition']
                        ),
                    dueOn: $validated['due_on'] ?? null,
                    conditionNote:
                        $validated['condition_note'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'purchase_obligation' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->to(
                route(
                    'purchase-orders.show',
                    $receipt->order
                )
                .'#obligations-'.$receipt->public_id
            )
            ->with(
                'success',
                'Obligación económica registrada por '
                .$obligation->currency_code.' '
                .number_format(
                    $obligation->amount_minor / 100,
                    2,
                    ',',
                    '.'
                )
                .'. No se ejecutó ningún pago.'
            );
    }
}
