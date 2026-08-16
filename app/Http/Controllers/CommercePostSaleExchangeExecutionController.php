<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionData;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionLineData;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionManager;
use App\Domain\Commerce\CustomerCreditBalanceReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Http\Requests\ExecuteCommercePostSaleExchange;
use App\Models\CommercePostSaleExchangeSelection;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleExchangeExecutionController extends Controller
{
    public function create(
        Request $request,
        CommercePostSaleExchangeSelection $exchangeSelection,
        CurrentOrganization $currentOrganization,
        CustomerCreditBalanceReader $creditBalances
    ): View {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $exchangeSelection
                ->organization_id
                === $organizationId,
            404
        );

        $exchangeSelection->load([
            'resolution.request.sale',
            'resolution.resolvedBy',
            'selectedBy',
            'lines.product',
            'execution.inventoryMovement',
            'execution.executedBy',
            'execution.payments.financialAccount',
            'execution.creditGrant.party',
        ]);

        $selectionLines =
            $exchangeSelection
                ->lines
                ->sortBy('sequence')
                ->values();

        $balances =
            InventoryBalance::query()
                ->forOrganization($organizationId)
                ->whereIn(
                    'catalog_product_id',
                    $selectionLines
                        ->pluck('catalog_product_id')
                        ->all()
                )
                ->with('location')
                ->get()
                ->filter(
                    fn (InventoryBalance $balance): bool =>
                        $balance->location !== null
                        && $balance->location->active
                        && BigDecimal::of(
                            (string) $balance->quantity
                        )->isGreaterThan(
                            BigDecimal::zero()
                        )
                );

        $availability =
            $selectionLines
                ->mapWithKeys(
                    function ($line) use ($balances): array {
                        $required =
                            BigDecimal::of(
                                (string) $line->quantity
                            );

                        $options =
                            $balances
                                ->where(
                                    'catalog_product_id',
                                    $line->catalog_product_id
                                )
                                ->filter(
                                    fn (
                                        InventoryBalance $balance
                                    ): bool =>
                                        ! BigDecimal::of(
                                            (string) $balance->quantity
                                        )->isLessThan(
                                            $required
                                        )
                                )
                                ->sortBy(
                                    fn (
                                        InventoryBalance $balance
                                    ): string =>
                                        mb_strtolower(
                                            $balance->location->name
                                            .'|'
                                            .$balance->condition->value
                                        )
                                )
                                ->values();

                        return [
                            $line->id =>
                                $options,
                        ];
                    }
                );

        $accounts =
            FinancialAccount::query()
                ->forOrganization($organizationId)
                ->where('active', true)
                ->where(
                    'currency_code',
                    $exchangeSelection
                        ->currency_code
                )
                ->where(
                    'type',
                    '!=',
                    FinancialAccountType::CashReserve
                        ->value
                )
                ->orderBy('name')
                ->get();

        $methods =
            collect(
                CommercePaymentMethod::cases()
            )
                ->values();

        $sale =
            $exchangeSelection
                ->resolution
                ->request
                ->sale;

        $customerCreditBalanceMinor =
            $sale->customer_business_party_id
                !== null
                ? $creditBalances
                    ->balanceMinor(
                        $organizationId,
                        (int) $sale
                            ->customer_business_party_id,
                        (string) $exchangeSelection
                            ->currency_code
                    )
                : 0;

        return view(
            'commerce-post-sale.exchange-execution-create',
            [
                'selection' =>
                    $exchangeSelection,
                'selectionLines' =>
                    $selectionLines,
                'availability' =>
                    $availability,
                'accounts' =>
                    $accounts,
                'paymentMethods' =>
                    $methods,
                'customerCreditBalanceMinor' =>
                    $customerCreditBalanceMinor,
                'idempotencyKey' =>
                    'ui:commerce-post-sale-exchange-execution:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        ExecuteCommercePostSaleExchange $request,
        CommercePostSaleExchangeSelection $exchangeSelection,
        CommercePostSaleExchangeExecutionManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $exchangeSelection
                ->organization_id
                === $organizationId,
            404
        );

        $validated =
            $request->validated();

        try {
            $execution =
                $manager->execute(
                    $exchangeSelection,
                    new CommercePostSaleExchangeExecutionData(
                        lines:
                            collect(
                                $validated['lines']
                            )
                                ->map(
                                    fn (
                                        array $line
                                    ): CommercePostSaleExchangeExecutionLineData =>
                                        new CommercePostSaleExchangeExecutionLineData(
                                            commercePostSaleExchangeSelectionLineId:
                                                (int) $line[
                                                    'commerce_post_sale_exchange_selection_line_id'
                                                ],
                                            sourceLocationId:
                                                (int) $line[
                                                    'source_location_id'
                                                ],
                                            condition:
                                                InventoryCondition::from(
                                                    $line[
                                                        'condition'
                                                    ]
                                                )
                                        )
                                )
                                ->values()
                                ->all(),
                        payments:
                            collect(
                                $validated['payments']
                                    ?? []
                            )
                                ->map(
                                    fn (
                                        array $payment
                                    ): CommercePaymentData =>
                                        new CommercePaymentData(
                                            method:
                                                CommercePaymentMethod::from(
                                                    $payment[
                                                        'method'
                                                    ]
                                                ),
                                            amountMinor:
                                                $this->moneyToMinor(
                                                    (string) $payment[
                                                        'amount'
                                                    ]
                                                ),
                                            reference:
                                                $payment[
                                                    'reference'
                                                ] ?? null,
                                            notes:
                                                $payment[
                                                    'notes'
                                                ] ?? null,
                                            financialAccountId:
                                                filled(
                                                    $payment[
                                                        'financial_account_id'
                                                    ] ?? null
                                                )
                                                    ? (int) $payment[
                                                        'financial_account_id'
                                                    ]
                                                    : null,
                                            tenderedAmountMinor:
                                                filled(
                                                    $payment[
                                                        'tendered_amount'
                                                    ] ?? null
                                                )
                                                    ? $this->moneyToMinor(
                                                        (string) $payment[
                                                            'tendered_amount'
                                                        ]
                                                    )
                                                    : null
                                        )
                                )
                                ->values()
                                ->all(),
                        idempotencyKey:
                            $validated[
                                'idempotency_key'
                            ],
                        notes:
                            $validated['notes']
                                ?? null
                    ),
                    $request->user()
                );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'post_sale_execution' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'commerce-post-sale.show',
                $exchangeSelection
                    ->resolution
                    ->request
            )
            ->with(
                'success',
                'Cambio ejecutado. Movimiento de inventario #'
                .$execution->inventory_movement_id
                .' confirmado.'
            );
    }

    private function moneyToMinor(
        string $value
    ): int {
        $normalized =
            str_replace(
                ',',
                '.',
                trim($value)
            );

        if (
            ! preg_match(
                '/^(\d{1,12})(?:\.(\d{1,2}))?$/',
                $normalized,
                $matches
            )
        ) {
            throw new DomainException(
                'Un importe de la diferencia no posee formato monetario válido.'
            );
        }

        $whole =
            (int) $matches[1];

        $fraction =
            str_pad(
                $matches[2] ?? '',
                2,
                '0'
            );

        return ($whole * 100)
            + (int) $fraction;
    }
}
