<?php

namespace App\Http\Controllers;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\CashSecurityDropManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashCountDifferenceReason;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CashSecurityDropReason;
use App\Enums\CashSecurityDropRequestStatus;
use App\Enums\FinancialAccountType;
use App\Http\Requests\ApproveCashSecurityDropRequest;
use App\Http\Requests\CloseCashRegisterSessionRequest;
use App\Http\Requests\ExecuteCashSecurityDropRequest;
use App\Http\Requests\OpenCashRegisterSessionRequest;
use App\Http\Requests\RequestCashSecurityDropRequest;
use App\Http\Requests\ResolveCashSecurityDropRequest;
use App\Http\Requests\SaveCashRegisterRequest;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterClosure;
use App\Models\CashSecurityDropRequest;
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

        $ownDropRequests = $currentSession
            ? CashSecurityDropRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'cash_register_session_id',
                    $currentSession->id
                )
                ->where('requested_by_user_id', $actor->id)
                ->with([
                    'destinationFinancialAccount',
                    'approvedBy',
                    'executedBy',
                    'resolvedBy',
                    'movement',
                ])
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
            : collect();

        $pendingDropApprovals = $actor->can(
            'approve-cash-security-drop'
        )
            ? CashSecurityDropRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    CashSecurityDropRequestStatus::Pending->value
                )
                ->with([
                    'register',
                    'destinationFinancialAccount',
                    'requestedBy',
                ])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get()
            : collect();

        $hasBlockingDropRequests = $ownDropRequests->contains(
            fn (CashSecurityDropRequest $dropRequest): bool =>
                $dropRequest->status->blocksClosing()
        );

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
            'differenceReasons' =>
                CashCountDifferenceReason::cases(),
            'recentMovements' => $recentMovements,
            'ownDropRequests' => $ownDropRequests,
            'pendingDropApprovals' => $pendingDropApprovals,
            'hasBlockingDropRequests' => $hasBlockingDropRequests,
            'recentClosures' => CashRegisterClosure::query()
                ->forOrganization($organizationId)
                ->when(
                    ! $actor->can('supervise-cash-registers'),
                    fn ($query) => $query->where(
                        'opened_by_user_id',
                        $actor->id
                    )
                )
                ->with([
                    'register',
                    'financialAccount',
                    'openedBy',
                    'closedBy',
                ])
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
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

    public function requestSecurityDrop(
        RequestCashSecurityDropRequest $request,
        CashSecurityDropManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $destination = FinancialAccount::query()->findOrFail(
                $validated['destination_financial_account_id']
            );

            $dropRequest = $manager->request(
                $destination,
                $this->moneyMinor($validated['amount']),
                CashSecurityDropReason::from($validated['reason_code']),
                $validated['note'] ?: null,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_security_drop' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                'Solicitud de retiro enviada por '.
                number_format(
                    $dropRequest->amount_minor / 100,
                    2,
                    ',',
                    '.'
                ).
                ' '.$dropRequest->currency_code.'. Espera autorización.'
            );
    }

    public function approveSecurityDrop(
        ApproveCashSecurityDropRequest $request,
        CashSecurityDropRequest $cashSecurityDropRequest,
        CashSecurityDropManager $manager
    ): RedirectResponse {
        $this->guardSecurityDropRequest(
            $request,
            $cashSecurityDropRequest
        );
        $validated = $request->validated();

        try {
            $manager->approve(
                $cashSecurityDropRequest,
                $validated['approval_note'] ?: null,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'cash_security_drop_approval' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with('success', 'Retiro de seguridad autorizado.');
    }

    public function rejectSecurityDrop(
        ResolveCashSecurityDropRequest $request,
        CashSecurityDropRequest $cashSecurityDropRequest,
        CashSecurityDropManager $manager
    ): RedirectResponse {
        $this->guardSecurityDropRequest(
            $request,
            $cashSecurityDropRequest
        );
        $validated = $request->validated();

        try {
            $manager->reject(
                $cashSecurityDropRequest,
                $validated['resolution_note'],
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'cash_security_drop_approval' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with('success', 'Solicitud de retiro rechazada.');
    }

    public function cancelSecurityDrop(
        ResolveCashSecurityDropRequest $request,
        CashSecurityDropRequest $cashSecurityDropRequest,
        CashSecurityDropManager $manager
    ): RedirectResponse {
        $this->guardSecurityDropRequest(
            $request,
            $cashSecurityDropRequest
        );
        $validated = $request->validated();

        try {
            $manager->cancel(
                $cashSecurityDropRequest,
                $validated['resolution_note'],
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_security_drop' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with('success', 'Solicitud de retiro cancelada.');
    }

    public function executeSecurityDrop(
        ExecuteCashSecurityDropRequest $request,
        CashSecurityDropRequest $cashSecurityDropRequest,
        CashSecurityDropManager $manager
    ): RedirectResponse {
        $this->guardSecurityDropRequest(
            $request,
            $cashSecurityDropRequest
        );
        $validated = $request->validated();

        try {
            $movement = $manager->execute(
                $cashSecurityDropRequest,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_security_drop' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                'Retiro autorizado ejecutado por '.
                number_format(
                    $movement->amount_minor / 100,
                    2,
                    ',',
                    '.'
                ).
                ' '.$movement->currency_code.'.'
            );
    }

    public function close(
        CloseCashRegisterSessionRequest $request,
        CashRegisterSessionManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $closure = $manager->closeCurrent(
                $this->moneyMinor($validated['counted_amount']),
                filled($validated['difference_reason'] ?? null)
                    ? CashCountDifferenceReason::from(
                        $validated['difference_reason']
                    )
                    : null,
                $validated['closing_note'] ?: null,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'cash_closure' => $exception->getMessage(),
                ]);
        }

        $difference = number_format(
            $closure->difference_minor / 100,
            2,
            ',',
            '.'
        );

        return redirect()
            ->route('cash-registers.index')
            ->with(
                'success',
                'Turno cerrado. Diferencia de arqueo: '.
                $closure->currency_code.' '.$difference.'.'
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

    private function guardSecurityDropRequest(
        Request $request,
        CashSecurityDropRequest $dropRequest
    ): void {
        $organizationId = app(CurrentOrganization::class)
            ->id($request->user());

        abort_unless(
            (int) $dropRequest->organization_id === $organizationId,
            404
        );
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
