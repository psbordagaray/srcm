<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerCreditBalanceReader;
use App\Domain\Commerce\CustomerCreditExposureReader;
use App\Domain\Commerce\CustomerReceivableBalanceReader;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerAdvanceStatus;
use App\Models\Customer;
use App\Models\CustomerAdvance;
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
        CustomerCreditExposureReader $creditExposure,
        CustomerCreditBalanceReader $creditBalance,
        CashRegisterSessionManager $cashSessions
    ): View {
        $organizationId =
            $currentOrganization->id(
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

        $financialAccounts =
            FinancialAccount::query()
                ->forOrganization(
                    $organizationId
                )
                ->where('active', true)
                ->orderBy('currency_code')
                ->orderBy('name')
                ->get();

        $currencies = collect([
            'ARS',
            'USD',
        ]);

        $creditPolicySnapshots =
            $currencies->mapWithKeys(
                fn (string $currency): array => [
                    $currency =>
                        $creditExposure->snapshot(
                            $customer,
                            $currency,
                            $request->user()
                        ),
                ]
            );

        $creditBalanceSnapshots =
            $currencies->mapWithKeys(
                fn (string $currency): array => [
                    $currency =>
                        $creditBalance->balanceMinor(
                            $organizationId,
                            (int) $customer
                                ->business_party_id,
                            $currency
                        ),
                ]
            );

        $advances =
            CustomerAdvance::query()
                ->forOrganization(
                    $organizationId
                )
                ->where(
                    'business_party_id',
                    $customer->business_party_id
                )
                ->where(
                    'status',
                    CustomerAdvanceStatus::
                        Confirmed->value
                )
                ->with([
                    'financialAccount',
                    'creditAllocations.consumption',
                ])
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->get();

        return view(
            'customers.account',
            [
                'customer' => $customer,
                'party' => $customer->party,
                'account' => $account,
                'creditPolicySnapshots' =>
                    $creditPolicySnapshots,
                'creditBalanceSnapshots' =>
                    $creditBalanceSnapshots,
                'creditPolicyIdempotencyKeys' => [
                    'ARS' =>
                        'customer-credit-policy-ui:'
                        .Str::uuid(),
                    'USD' =>
                        'customer-credit-policy-ui:'
                        .Str::uuid(),
                ],
                'advances' => $advances,
                'financialAccounts' =>
                    $financialAccounts,
                'methods' => collect(
                    CommercePaymentMethod::cases()
                )
                    ->reject(
                        fn (
                            CommercePaymentMethod
                            $method
                        ): bool =>
                            $method ===
                            CommercePaymentMethod::
                                AccountCredit
                    )
                    ->values(),
                'currentCashSession' =>
                    $cashSessions->currentFor(
                        $request->user()
                    ),
                'idempotencyKey' =>
                    'customer-collection-ui:'
                    .Str::uuid(),
                'advanceIdempotencyKey' =>
                    'customer-advance-ui:'
                    .Str::uuid(),
            ]
        );
    }
}
