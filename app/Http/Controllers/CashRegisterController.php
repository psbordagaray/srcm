<?php

namespace App\Http\Controllers;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CashSecurityDropReason;
use App\Enums\FinancialAccountType;
use App\Http\Requests\OpenCashRegisterSessionRequest;
use App\Http\Requests\RecordCashSecurityDropRequest;
use App\Http\Requests\SaveCashRegisterRequest;
use App\Models\CashMovement;
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
        CashRegisterSessionManager $sessions,
        CashLedgerRecorder $ledger
    ): View {
        $actor = $request->user();
        $organizationId = $currentOrganization->id($actor);
        $currentSession = $sessions->currentFor($actor);

        $registers = CashRegister::query()
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
            ->get();

        $expectedBySession = [];

        foreach ($registers as $register) {
            foreach ($register->sessions as $openSession) {
                $isOwn = $currentSession
                    && (int) $currentSession->id === (int) $openSession->id;

                if (
                    $isOwn
                    || $actor->can('supervise-cash-registers')
                ) {
                    $expectedBySession[$openSession->id] =
                        $ledger->expectedAmountMinor(
                            $openSession,
                            $actor
                        );
                }
            }
        }

        $recentMovements = $currentSession
            ? CashMovement::query()
                ->forOrganization($organizationId)
                ->where(
                    'cash_register_session_id',
                    $currentSession->id
                )
                ->with([
                    'destinationFinancialAccount',
                    'recordedBy',
                ])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
            : collect();

        return view('cash-registers.index', [
            'registers' => $registers,
            'currentSession' => $currentSession,
            'currentExpectedMinor' => $currentSession
                ? $ledger->expectedAmountMinor(
                    $currentSession,
                    $actor
                )
                : null,
            'expectedBySession' => $expectedBySession,
            'treasuryAccounts' => FinancialAccount::query()
                ->forOrganization($organizationId)
                ->where('active', true)
                ->where(
                    'type',
                    FinancialAccountType::CashReserve
                )
                ->when(
                    $currentSession,
                    fn ($query) => $query->where(
                        'currency_code',
                        $currentSession->currency_code
                    )
                )
                ->orderBy('name')
                ->get(),
            'dropReasons' => CashSecurityDropReason::cases(),
            'recentMovements' => $recentMovements,
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

    public function securityDrop(
        RecordCashSecurityDropRequest $request,
        CashLedgerRecorder $ledger
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $destination = FinancialAccount::query()
                ->findOrFail(
                    $validated['destination_financial_account_id']
                );

            $movement = $ledger->recordSecurityDrop(
                $destination,
                $this->moneyMinor($validated['amount']),
                CashSecurityDropReason::from(
                    $validated['reason_code']
                ),
                $validated['note'] ?: null,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_movement' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                'Retiro de seguridad registrado por '.
                number_format(
                    $movement->amount_minor / 100,
                    2,
                    ',',
                    '.'
                ).
                ' '.$movement->currency_code.'.'
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
