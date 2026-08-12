<?php

namespace App\Http\Controllers;

use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Http\Requests\OpenCashRegisterSessionRequest;
use App\Http\Requests\SaveCashRegisterRequest;
use App\Models\CashRegister;
use App\Models\FinancialAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        CashRegisterSessionManager $sessions
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        return view('cash-registers.index', [
            'registers' => CashRegister::query()
                ->forOrganization($organizationId)
                ->with([
                    'financialAccount',
                    'sessions' => fn ($query) => $query
                        ->where(
                            'status',
                            CashRegisterSessionStatus::Open->value
                        )
                        ->with('openedBy'),
                ])
                ->orderByDesc('active')
                ->orderBy('name')
                ->get(),
            'currentSession' => $sessions->currentFor(
                $request->user()
            ),
        ]);
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        return view('cash-registers.create', [
            'accounts' => $this->availableAccounts($organizationId),
        ]);
    }

    public function store(
        SaveCashRegisterRequest $request,
        CashRegisterManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $account = FinancialAccount::query()->findOrFail(
                $validated['financial_account_id']
            );

            $register = $manager->create(
                $validated['name'],
                $account,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_register' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                "Caja operativa {$register->name} creada."
            );
    }

    public function edit(
        Request $request,
        CashRegister $cashRegister,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardRegister(
            $request,
            $cashRegister,
            $currentOrganization
        );

        return view('cash-registers.edit', [
            'register' => $cashRegister->load('financialAccount'),
            'accounts' => $this->availableAccounts(
                $organizationId,
                $cashRegister
            ),
        ]);
    }

    public function update(
        SaveCashRegisterRequest $request,
        CashRegister $cashRegister,
        CurrentOrganization $currentOrganization,
        CashRegisterManager $manager
    ): RedirectResponse {
        $this->guardRegister(
            $request,
            $cashRegister,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $account = FinancialAccount::query()->findOrFail(
                $validated['financial_account_id']
            );

            $register = $manager->update(
                $cashRegister,
                $validated['name'],
                $account,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_register' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                "Caja operativa {$register->name} actualizada."
            );
    }

    public function toggleActive(
        Request $request,
        CashRegister $cashRegister,
        CurrentOrganization $currentOrganization,
        CashRegisterManager $manager
    ): RedirectResponse {
        $this->guardRegister(
            $request,
            $cashRegister,
            $currentOrganization
        );

        try {
            $register = $manager->toggleActive(
                $cashRegister,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'cash_register' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                $register->active
                    ? 'Caja operativa activada.'
                    : 'Caja operativa inactivada.'
            );
    }

    public function open(
        OpenCashRegisterSessionRequest $request,
        CashRegister $cashRegister,
        CurrentOrganization $currentOrganization,
        CashRegisterSessionManager $manager
    ): RedirectResponse {
        $this->guardRegister(
            $request,
            $cashRegister,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $session = $manager->open(
                $cashRegister,
                $this->moneyMinor($validated['opening_amount']),
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_session' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                'Turno abierto en '.$session->register->name.'.'
            );
    }

    private function guardRegister(
        Request $request,
        CashRegister $register,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $register->organization_id === $organizationId,
            404
        );

        return $organizationId;
    }

    private function availableAccounts(
        int $organizationId,
        ?CashRegister $current = null
    ) {
        $used = CashRegister::query()
            ->forOrganization($organizationId)
            ->when(
                $current,
                fn ($query) => $query->where(
                    'id',
                    '<>',
                    $current->id
                )
            )
            ->pluck('financial_account_id');

        return FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where('active', true)
            ->where('type', FinancialAccountType::CashBox)
            ->whereNotIn('id', $used)
            ->orderBy('currency_code')
            ->orderBy('name')
            ->get();
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of(
            str_replace(',', '.', trim($value))
        )
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
