<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerAdvanceData;
use App\Domain\Commerce\CustomerAdvanceManager;
use App\Enums\CommercePaymentMethod;
use App\Http\Requests\StoreCustomerAdvanceRequest;
use App\Models\Customer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CustomerAdvanceController extends Controller
{
    public function store(
        StoreCustomerAdvanceRequest $request,
        Customer $customer,
        CustomerAdvanceManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $manager->receive(
                $customer,
                new CustomerAdvanceData(
                    currencyCode:
                        $validated['currency_code'],
                    method:
                        CommercePaymentMethod::from(
                            $validated['method']
                        ),
                    amountMinor: $this->moneyMinor(
                        $validated['amount']
                    ),
                    financialAccountId:
                        $validated[
                            'financial_account_id'
                        ],
                    idempotencyKey:
                        $validated['idempotency_key'],
                    reference:
                        $validated['reference']
                            ?? null,
                    tenderedAmountMinor: filled(
                        $validated[
                            'tendered_amount'
                        ] ?? null
                    )
                        ? $this->moneyMinor(
                            $validated[
                                'tendered_amount'
                            ]
                        )
                        : null,
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
                    'advance' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'customers.account',
                $customer
            )
            ->with(
                'success',
                'Anticipo confirmado: quedó disponible como saldo a favor del cliente.'
            );
    }

    private function moneyMinor(
        string $value
    ): int {
        return (int) (string) BigDecimal::of(
            str_replace(
                ',',
                '.',
                trim($value)
            )
        )
            ->multipliedBy(100)
            ->toScale(
                0,
                RoundingMode::Unnecessary
            )
            ->toBigInteger();
    }
}
