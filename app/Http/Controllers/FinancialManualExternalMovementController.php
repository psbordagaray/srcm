<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialManualExternalMovementData;
use App\Domain\Finance\FinancialManualExternalMovementManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FinancialManualExternalMovementController
    extends Controller
{
    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId =
            $currentOrganization->id(
                $request->user()
            );

        $accounts = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where('active', true)
            ->whereNotIn(
                'type',
                [
                    FinancialAccountType::CashBox->value,
                    FinancialAccountType::CashReserve->value,
                ]
            )
            ->orderBy('name')
            ->get();

        return view(
            'financial-reconciliation.manual-movement',
            [
                'accounts' => $accounts,
                'idempotencyKey' =>
                    (string) Str::uuid(),
                'defaultOccurredAt' =>
                    CarbonImmutable::now(
                        config(
                            'app.display_timezone'
                        )
                    )->format('Y-m-d\TH:i'),
                'displayTimezone' =>
                    (string) config(
                        'app.display_timezone'
                    ),
            ]
        );
    }

    public function store(
        Request $request,
        CurrentOrganization $currentOrganization,
        FinancialManualExternalMovementManager $manager
    ): RedirectResponse {
        $validated = $request->validate([
            'financial_account' => [
                'required',
                'uuid',
            ],
            'direction' => [
                'required',
                'in:credit,debit',
            ],
            'gross_amount' => [
                'required',
                'string',
                'max:30',
                'regex:/^(0|[1-9]\d*)(?:[.,]\d{1,2})?$/D',
            ],
            'fee_amount' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^(0|[1-9]\d*)(?:[.,]\d{1,2})?$/D',
            ],
            'withholding_amount' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^(0|[1-9]\d*)(?:[.,]\d{1,2})?$/D',
            ],
            'net_amount' => [
                'required',
                'string',
                'max:30',
                'regex:/^(0|[1-9]\d*)(?:[.,]\d{1,2})?$/D',
            ],
            'occurred_at' => [
                'required',
                'date_format:Y-m-d\TH:i',
            ],
            'external_operation_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'reference' => [
                'nullable',
                'string',
                'max:500',
            ],
            'manual_reason' => [
                'required',
                'string',
                'min:10',
                'max:500',
            ],
            'idempotency_key' => [
                'required',
                'uuid',
            ],
        ]);

        $organizationId =
            $currentOrganization->id(
                $request->user()
            );

        $account = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where(
                'public_id',
                $validated['financial_account']
            )
            ->first();

        if (! $account) {
            abort(404);
        }

        try {
            $occurredAt =
                CarbonImmutable::createFromFormat(
                    'Y-m-d\TH:i',
                    $validated['occurred_at'],
                    config(
                        'app.display_timezone'
                    )
                );

            if (
                ! $occurredAt
                || $occurredAt->format(
                    'Y-m-d\TH:i'
                ) !== $validated['occurred_at']
            ) {
                throw new DomainException(
                    'La fecha/hora del movimiento externo no es válida.'
                );
            }

            $movement = $manager->record(
                $account,
                new FinancialManualExternalMovementData(
                    direction:
                        FinancialMovementDirection::from(
                            $validated['direction']
                        ),
                    grossAmountMinor:
                        $this->amountMinor(
                            $validated[
                                'gross_amount'
                            ]
                        ),
                    feeAmountMinor:
                        $this->amountMinor(
                            $validated[
                                'fee_amount'
                            ] ?? '0'
                        ),
                    withholdingAmountMinor:
                        $this->amountMinor(
                            $validated[
                                'withholding_amount'
                            ] ?? '0'
                        ),
                    netAmountMinor:
                        $this->amountMinor(
                            $validated[
                                'net_amount'
                            ]
                        ),
                    occurredAt:
                        $occurredAt->utc(),
                    externalOperationId:
                        $validated[
                            'external_operation_id'
                        ] ?? null,
                    reference:
                        $validated[
                            'reference'
                        ] ?? null,
                    reason:
                        $validated[
                            'manual_reason'
                        ],
                    idempotencyKey:
                        $validated[
                            'idempotency_key'
                        ]
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with(
                    'error',
                    $exception->getMessage()
                );
        }

        return redirect()
            ->route(
                'financial-reconciliation.index'
            )
            ->with(
                'success',
                'Movimiento externo manual verificado: '
                    .$movement->public_id
                    .'. Queda disponible para conciliación explícita; no se concilió automáticamente.'
            );
    }

    private function amountMinor(
        string $value
    ): int {
        $value = str_replace(
            ',',
            '.',
            trim($value)
        );

        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D',
                $value,
                $matches
            ) !== 1
        ) {
            throw new DomainException(
                'Uno de los importes manuales no es válido.'
            );
        }

        $whole = (int) $matches[1];
        $fraction = str_pad(
            $matches[2] ?? '',
            2,
            '0'
        );

        if (
            $whole
            > intdiv(PHP_INT_MAX, 100)
        ) {
            throw new DomainException(
                'Uno de los importes manuales está fuera de rango.'
            );
        }

        return ($whole * 100)
            + (int) $fraction;
    }
}
