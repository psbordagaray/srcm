<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerCreditPolicyManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreCustomerCreditPolicyRequest;
use App\Models\Customer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CustomerCreditPolicyController extends Controller
{
    public function store(
        StoreCustomerCreditPolicyRequest $request,
        Customer $customer,
        CurrentOrganization $currentOrganization,
        CustomerCreditPolicyManager $manager
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        abort_unless(
            (int) $customer->organization_id
                === $organizationId,
            404
        );

        $validated = $request->validated();

        try {
            $manager->setLimit(
                $customer,
                $validated['currency_code'],
                $this->moneyMinor($validated['limit']),
                $validated['reason'],
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'credit_policy' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('customers.account', $customer)
            ->with(
                'success',
                'Nueva versión de política de crédito registrada.'
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
