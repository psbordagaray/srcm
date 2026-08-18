<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchasePaymentDisbursementManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Http\Requests\ApprovePurchasePaymentRequest;
use App\Http\Requests\ExecutePurchasePaymentRequest;
use App\Http\Requests\RequestPurchasePaymentRequest;
use App\Http\Requests\ResolvePurchasePaymentRequest;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class PurchasePaymentRequestController extends Controller
{
    public function store(
        RequestPurchasePaymentRequest $request,
        string $purchaseOrder,
        string $purchaseObligation,
        CurrentOrganization $currentOrganization,
        PurchasePaymentRequestManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $obligation = PurchaseObligation::query()
            ->forOrganization($organizationId)
            ->where('public_id', $purchaseObligation)
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
            $paymentRequest = $manager->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        (int) $obligation->id,
                    originFinancialAccountId:
                        $validated[
                            'origin_financial_account_id'
                        ],
                    amountMinor: $this->moneyMinor(
                        (string) $validated['amount']
                    ),
                    requestNote:
                        $validated['request_note'] ?? null,
                    idempotencyKey:
                        $validated['idempotency_key']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'purchase_payment_request' =>
                        $exception->getMessage(),
                ]);
        }

        return $this->redirectTo(
            $paymentRequest,
            'Solicitud de pago registrada. Sigue pendiente de autorización y no movió dinero.'
        );
    }

    public function approve(
        ApprovePurchasePaymentRequest $request,
        PurchasePaymentRequest $purchasePaymentRequest,
        PurchasePaymentRequestManager $manager
    ): RedirectResponse {
        try {
            $paymentRequest = $manager->approve(
                $purchasePaymentRequest,
                $request->validated('approval_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_approval' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $paymentRequest,
            'Pago autorizado. No se ejecutó ningún pago ni movimiento de caja.'
        );
    }

    public function execute(
        ExecutePurchasePaymentRequest $request,
        PurchasePaymentRequest $purchasePaymentRequest,
        PurchasePaymentDisbursementManager $manager
    ): RedirectResponse {
        try {
            $disbursement = $manager->executeIndividual(
                $purchasePaymentRequest,
                $request->validated('execution_reference'),
                $request->validated('execution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_execution' =>
                    $exception->getMessage(),
            ]);
        }

        $message = $disbursement->channel
            === PurchasePaymentDisbursementChannel::Cash
            ? 'Pago cash ejecutado: Caja fue afectada por un único egreso confirmado.'
            : 'Pago non-cash ejecutado: quedó pendiente la verificación de evidencia externa.';

        return $this->redirectTo(
            $disbursement->individualRequest,
            $message
        );
    }
    public function reject(
        ResolvePurchasePaymentRequest $request,
        PurchasePaymentRequest $purchasePaymentRequest,
        PurchasePaymentRequestManager $manager
    ): RedirectResponse {
        try {
            $paymentRequest = $manager->reject(
                $purchasePaymentRequest,
                $request->validated('resolution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $paymentRequest,
            'Solicitud de pago rechazada. No se movió dinero.'
        );
    }

    public function cancel(
        ResolvePurchasePaymentRequest $request,
        PurchasePaymentRequest $purchasePaymentRequest,
        PurchasePaymentRequestManager $manager
    ): RedirectResponse {
        try {
            $paymentRequest = $manager->cancel(
                $purchasePaymentRequest,
                $request->validated('resolution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $paymentRequest,
            'Solicitud de pago cancelada. No se movió dinero.'
        );
    }

    public function expire(
        ResolvePurchasePaymentRequest $request,
        PurchasePaymentRequest $purchasePaymentRequest,
        PurchasePaymentRequestManager $manager
    ): RedirectResponse {
        try {
            $paymentRequest = $manager->expire(
                $purchasePaymentRequest,
                $request->validated('resolution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $paymentRequest,
            'Autorización de pago marcada como vencida. No se movió dinero.'
        );
    }

    private function redirectTo(
        PurchasePaymentRequest $request,
        string $message
    ): RedirectResponse {
        $request->loadMissing('obligation.order');

        return redirect()
            ->to(
                route(
                    'purchase-orders.show',
                    $request->obligation->order
                )
                .'#payment-request-'.$request->public_id
            )
            ->with('success', $message);
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of($value)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
