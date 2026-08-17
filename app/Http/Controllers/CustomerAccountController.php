<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerReceivableBalanceReader;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Models\Customer;
use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerAccountController extends Controller
{
    public function show(
        Request $request,
        Customer $customer,
        CurrentOrganization $currentOrganization,
        CustomerReceivableBalanceReader $reader,
        CashRegisterSessionManager $cashSessions
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        abort_unless(
            (int) $customer->organization_id
                === $organizationId,
            404
        );

        $customer->loadMissing('party');

        $account = $reader->read(
            $customer,
            $request->user()
        );

        $financialAccounts = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where('active', true)
            ->orderBy('currency_code')
            ->orderBy('name')
            ->get();

        return view('customers.account', [
            'customer' => $customer,
            'party' => $customer->party,
            'account' => $account,
            'financialAccounts' => $financialAccounts,
            'methods' => collect(
                CommercePaymentMethod::cases()
            )
                ->reject(
                    fn (CommercePaymentMethod $method): bool =>
                        $method ===
                            CommercePaymentMethod::AccountCredit
                )
                ->values(),
            'currentCashSession' =>
                $cashSessions->currentFor(
                    $request->user()
                ),
            'idempotencyKey' =>
                'customer-collection-ui:'.Str::uuid(),
        ]);
    }
}
