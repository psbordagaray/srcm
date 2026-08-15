<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialReconciliationCenterReader;
use App\Domain\Finance\FinancialReconciliationDecisionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePayment;
use App\Models\FinancialExternalMovement;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialReconciliationController extends Controller
{
    public function reconcileCandidate(
        Request $request,
        string $commercePayment,
        string $financialExternalMovement,
        CurrentOrganization $currentOrganization,
        FinancialReconciliationDecisionManager $decisions
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $payment = CommercePayment::query()
            ->forOrganization($organizationId)
            ->whereKey((int) $commercePayment)
            ->firstOrFail();

        $movement = FinancialExternalMovement::query()
            ->forOrganization($organizationId)
            ->where(
                'public_id',
                $financialExternalMovement
            )
            ->firstOrFail();

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $event = $decisions->reconcileCandidate(
                $payment,
                $movement,
                $request->user(),
                $validated['note'] ?? null
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'reconciliation' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('financial-reconciliation.index')
            ->with(
                'success',
                'Conciliación registrada: '
                    .$event->status->value.'.'
            );
    }

    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        FinancialReconciliationCenterReader $reader
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        return view('financial-reconciliation.index', [
            'items' => $reader->read($organizationId),
        ]);
    }
}
