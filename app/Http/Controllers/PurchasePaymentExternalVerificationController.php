<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchasePaymentExternalVerificationManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\VerifyPurchasePaymentExternalMovementRequest;
use App\Models\FinancialExternalMovement;
use App\Models\PurchasePaymentDisbursement;
use DomainException;
use Illuminate\Http\RedirectResponse;

class PurchasePaymentExternalVerificationController extends Controller
{
    public function store(
        VerifyPurchasePaymentExternalMovementRequest $request,
        PurchasePaymentDisbursement $purchasePaymentDisbursement,
        string $financialExternalMovement,
        CurrentOrganization $currentOrganization,
        PurchasePaymentExternalVerificationManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $movement = FinancialExternalMovement::query()
            ->forOrganization($organizationId)
            ->where(
                'public_id',
                $financialExternalMovement
            )
            ->firstOrFail();

        try {
            $verification = $manager->verify(
                $purchasePaymentDisbursement,
                $movement,
                $request->validated('idempotency_key'),
                $request->user(),
                $request->validated('note')
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_external_verification' =>
                    $exception->getMessage(),
            ]);
        }

        $message =
            (int) $verification->amount_difference_minor === 0
            && (int) $verification
                ->financialMovement->fee_amount_minor === 0
            && (int) $verification
                ->financialMovement
                ->withholding_amount_minor === 0
                ? 'Débito externo verificado por importe exacto.'
                : 'Evidencia externa vinculada con diferencia explícita pendiente de revisión.';

        return redirect()
            ->to(
                route(
                    'purchase-payment-operations.index'
                )
                .'#disbursement-'
                .$purchasePaymentDisbursement->public_id
            )
            ->with('success', $message);
    }
}
