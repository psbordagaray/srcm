<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderHealthProbeRunner;
use App\Domain\Finance\FinancialProviderOperationalStatusReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        FinancialProviderOperationalStatusReader $providerStatus
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        $accounts = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->with('providerConnection')
            ->orderByDesc('active')
            ->orderBy('currency_code')
            ->orderBy('name')
            ->get();

        $providerStatuses = [];

        foreach ($accounts as $account) {
            if ($account->providerConnection) {
                $providerStatuses[$account->getKey()] =
                    $providerStatus->read(
                        $account->providerConnection,
                        FinancialProviderCapability::Read
                    );
            }
        }

        return view('financial-accounts.index', [
            'accounts' => $accounts,
            'providerStatuses' => $providerStatuses,
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

    public function probeProviderReadHealth(
        Request $request,
        FinancialProviderConnection $financialProviderConnection,
        CurrentOrganization $currentOrganization,
        FinancialProviderHealthProbeRunner $runner
    ): RedirectResponse {
        abort_unless(
            (int) $financialProviderConnection->organization_id
                === $currentOrganization->id($request->user()),
            404
        );

        try {
            $check = $runner->run(
                $financialProviderConnection,
                FinancialProviderCapability::Read
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'provider_health' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('financial-accounts.index')
            ->with(
                'success',
                'Health check de proveedor registrado: '
                    .$check->health_status->value.'.'
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
