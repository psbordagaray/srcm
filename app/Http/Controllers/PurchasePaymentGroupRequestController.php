<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchasePaymentDisbursementManager;
use App\Domain\Purchase\PurchasePaymentGroupItemData;
use App\Domain\Purchase\PurchasePaymentGroupRequestData;
use App\Domain\Purchase\PurchasePaymentGroupRequestManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Http\Requests\ApprovePurchasePaymentGroupRequest;
use App\Http\Requests\ExecutePurchasePaymentRequest;
use App\Http\Requests\RequestPurchasePaymentGroupRequest;
use App\Http\Requests\ResolvePurchasePaymentGroupRequest;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentGroupRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class PurchasePaymentGroupRequestController extends Controller
{
    public function store(
        RequestPurchasePaymentGroupRequest $request,
        CurrentOrganization $currentOrganization,
        PurchasePaymentGroupRequestManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );
        $validated = $request->validated();
        $publicIds = collect($validated['items'])
            ->pluck('purchase_obligation_id')
            ->values();
        $obligations = PurchaseObligation::query()
            ->forOrganization($organizationId)
            ->whereIn('public_id', $publicIds)
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        if ($obligations->count() !== $publicIds->count()) {
            return back()
                ->withInput()
                ->withErrors([
                    'purchase_payment_group' =>
                        'Una o más obligaciones agrupadas no pertenecen a la organización activa.',
                ]);
        }

        $items = collect($validated['items'])
            ->map(function (array $item) use ($obligations): PurchasePaymentGroupItemData {
                $obligation = $obligations->get(
                    $item['purchase_obligation_id']
                );

                return new PurchasePaymentGroupItemData(
                    purchaseObligationId:
                        (int) $obligation->id,
                    amountMinor: $this->moneyMinor(
                        (string) $item['amount']
                    )
                );
            })
            ->all();

        try {
            $group = $manager->request(
                new PurchasePaymentGroupRequestData(
                    originFinancialAccountId:
                        (int) $validated[
                            'origin_financial_account_id'
                        ],
                    items: $items,
                    idempotencyKey:
                        $validated['idempotency_key'],
                    requestNote:
                        $validated['request_note'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'purchase_payment_group' =>
                        $exception->getMessage(),
                ]);
        }

        return $this->redirectTo(
            $group,
            'Solicitud agrupada registrada. Reservó sus imputaciones pero no movió dinero.'
        );
    }

    public function approve(
        ApprovePurchasePaymentGroupRequest $request,
        PurchasePaymentGroupRequest $purchasePaymentGroupRequest,
        PurchasePaymentGroupRequestManager $manager
    ): RedirectResponse {
        try {
            $group = $manager->approve(
                $purchasePaymentGroupRequest,
                $request->validated('approval_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_group_approval' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $group,
            'Pago agrupado autorizado. Todavía no se ejecutó ningún desembolso.'
        );
    }

    public function reject(
        ResolvePurchasePaymentGroupRequest $request,
        PurchasePaymentGroupRequest $purchasePaymentGroupRequest,
        PurchasePaymentGroupRequestManager $manager
    ): RedirectResponse {
        try {
            $group = $manager->reject(
                $purchasePaymentGroupRequest,
                $request->validated('resolution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_group_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $group,
            'Solicitud agrupada rechazada. No se movió dinero.'
        );
    }

    public function cancel(
        ResolvePurchasePaymentGroupRequest $request,
        PurchasePaymentGroupRequest $purchasePaymentGroupRequest,
        PurchasePaymentGroupRequestManager $manager
    ): RedirectResponse {
        try {
            $group = $manager->cancel(
                $purchasePaymentGroupRequest,
                $request->validated('resolution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_group_resolution' =>
                    $exception->getMessage(),
            ]);
        }

        return $this->redirectTo(
            $group,
            'Solicitud agrupada cancelada. Sus obligaciones volvieron a quedar disponibles.'
        );
    }

    public function execute(
        ExecutePurchasePaymentRequest $request,
        PurchasePaymentGroupRequest $purchasePaymentGroupRequest,
        PurchasePaymentDisbursementManager $manager
    ): RedirectResponse {
        try {
            $disbursement = $manager->executeGroup(
                $purchasePaymentGroupRequest,
                $request->validated('execution_reference'),
                $request->validated('execution_note'),
                $request->validated('idempotency_key'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'purchase_payment_group_execution' =>
                    $exception->getMessage(),
            ]);
        }

        $message = $disbursement->channel
            === PurchasePaymentDisbursementChannel::Cash
            ? 'Pago agrupado cash ejecutado: se registró un único egreso por el total.'
            : 'Pago agrupado non-cash ejecutado: quedó pendiente la verificación de evidencia externa.';

        return $this->redirectTo(
            $disbursement->groupRequest,
            $message
        );
    }

    private function redirectTo(
        PurchasePaymentGroupRequest $request,
        string $message
    ): RedirectResponse {
        return redirect()
            ->to(
                route('purchase-payment-operations.index')
                .'#payment-group-'.$request->public_id
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
