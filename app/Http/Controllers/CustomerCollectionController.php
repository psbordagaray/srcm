<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Enums\CommercePaymentMethod;
use App\Http\Requests\StoreCustomerCollectionRequest;
use App\Models\Customer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CustomerCollectionController extends Controller
{
    public function store(
        StoreCustomerCollectionRequest $request,
        Customer $customer,
        CustomerCollectionManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $manager->collect(
                $customer,
                new CustomerCollectionData(
                    currencyCode:
                        $validated['currency_code'],
                    method: CommercePaymentMethod::from(
                        $validated['method']
                    ),
                    amountMinor: $this->moneyMinor(
                        $validated['amount']
                    ),
                    financialAccountId:
                        $validated['financial_account_id'],
                    allocations: collect(
                        $validated['allocations']
                    )
                        ->map(
                            fn (array $allocation):
                                CustomerCollectionAllocationData =>
                                    new CustomerCollectionAllocationData(
                                        customerReceivableId:
                                            $allocation[
                                                'customer_receivable_id'
                                            ],
                                        amountMinor:
                                            $this->moneyMinor(
                                                $allocation[
                                                    'amount'
                                                ]
                                            )
                                    )
                        )
                        ->all(),
                    idempotencyKey:
                        $validated['idempotency_key'],
                    reference:
                        $validated['reference'] ?? null,
                    tenderedAmountMinor: filled(
                        $validated['tendered_amount']
                            ?? null
                    )
                        ? $this->moneyMinor(
                            $validated['tendered_amount']
                        )
                        : null,
                    notes: $validated['notes'] ?? null,
                    retainExcessAsCredit:
                        (bool) $validated[
                            'retain_excess_as_credit'
                        ]
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'collection' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('customers.account', $customer)
            ->with(
                'success',
                'Cobranza confirmada; el excedente, si fue aceptado explícitamente, quedó como saldo a favor.'
            );
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
