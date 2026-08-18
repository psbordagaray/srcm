<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchasePaymentExternalResolutionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\PurchasePaymentExternalResolutionOutcome;
use App\Http\Requests\ResolvePurchasePaymentExternalDifferenceRequest;
use App\Models\PurchasePaymentExternalVerification;
use DomainException;
use Illuminate\Http\RedirectResponse;

class PurchasePaymentExternalResolutionController extends Controller
{
    public function store(
        ResolvePurchasePaymentExternalDifferenceRequest $request,
        string $paymentVerification,
        CurrentOrganization $currentOrganization,
        PurchasePaymentExternalResolutionManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );
        $verification =
            PurchasePaymentExternalVerification::query()
                ->forOrganization($organizationId)
                ->where(
                    'public_id',
                    $paymentVerification
                )
                ->firstOrFail();

        try {
            $resolution = $manager->resolve(
                $verification,
                PurchasePaymentExternalResolutionOutcome::from(
                    $request->validated('outcome')
                ),
                $request->validated('idempotency_key'),
                $request->user(),
                $request->validated('note')
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_external_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        $disbursement = $resolution
            ->verification
            ->disbursement;

        return redirect()
            ->to(
                route('purchase-payment-operations.index')
                .'#disbursement-'.$disbursement->public_id
            )
            ->with(
                'success',
                $resolution->outcome->closesReview()
                    ? 'Excepción externa documentada sin modificar CxP.'
                    : 'Seguimiento externo documentado y pendiente.'
            );
    }
}
