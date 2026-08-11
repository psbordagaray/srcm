<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\FinancialAccount;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        return view('financial-accounts.index', [
            'accounts' => FinancialAccount::query()
                ->forOrganization($organizationId)
                ->orderByDesc('active')
                ->orderBy('currency_code')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('financial-accounts.create', [
            'types' => FinancialAccountType::cases(),
        ]);
    }

    public function store(
        StoreFinancialAccountRequest $request,
        FinancialAccountManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $account = $manager->create(
                name: $validated['name'],
                type: FinancialAccountType::from($validated['type']),
                currencyCode: $validated['currency_code'],
                actor: $request->user(),
                provider: $validated['provider'] ?? null,
                externalLabel: $validated['external_label'] ?? null
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['financial_account' => $exception->getMessage()]);
        }

        return redirect()
            ->route('financial-accounts.index')
            ->with(
                'success',
                "Cuenta financiera {$account->name} creada."
            );
    }

    public function edit(
        Request $request,
        FinancialAccount $financialAccount,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardAccount(
            $request,
            $financialAccount,
            $currentOrganization
        );

        return view('financial-accounts.edit', [
            'account' => $financialAccount,
            'types' => FinancialAccountType::cases(),
        ]);
    }

    public function update(
        UpdateFinancialAccountRequest $request,
        FinancialAccount $financialAccount,
        CurrentOrganization $currentOrganization,
        FinancialAccountManager $manager
    ): RedirectResponse {
        $this->guardAccount(
            $request,
            $financialAccount,
            $currentOrganization
        );

        $validated = $request->validated();

        try {
            $account = $manager->update(
                account: $financialAccount,
                name: $validated['name'],
                type: FinancialAccountType::from($validated['type']),
                currencyCode: $validated['currency_code'],
                actor: $request->user(),
                provider: $validated['provider'] ?? null,
                externalLabel: $validated['external_label'] ?? null
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['financial_account' => $exception->getMessage()]);
        }

        return redirect()
            ->route('financial-accounts.index')
            ->with(
                'success',
                "Cuenta financiera {$account->name} actualizada."
            );
    }

    public function toggleActive(
        Request $request,
        FinancialAccount $financialAccount,
        CurrentOrganization $currentOrganization,
        FinancialAccountManager $manager
    ): RedirectResponse {
        $this->guardAccount(
            $request,
            $financialAccount,
            $currentOrganization
        );

        try {
            $account = $manager->toggleActive(
                $financialAccount,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withErrors(['financial_account' => $exception->getMessage()]);
        }

        return redirect()
            ->route('financial-accounts.index')
            ->with(
                'success',
                $account->active
                    ? 'Cuenta financiera activada.'
                    : 'Cuenta financiera inactivada.'
            );
    }

    private function guardAccount(
        Request $request,
        FinancialAccount $account,
        CurrentOrganization $currentOrganization
    ): void {
        abort_unless(
            (int) $account->organization_id
                === $currentOrganization->id($request->user()),
            404
        );
    }
}
