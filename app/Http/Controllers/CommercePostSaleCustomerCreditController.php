<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleCustomerCreditManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Http\Requests\MaterializeCommercePostSaleCustomerCredit;
use App\Models\CommercePostSaleResolution;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CommercePostSaleCustomerCreditController extends Controller
{
    public function store(
        MaterializeCommercePostSaleCustomerCredit $request,
        CommercePostSaleResolution $commercePostSaleResolution,
        CommercePostSaleCustomerCreditManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commercePostSaleResolution
                ->organization_id
                === $organizationId,
            404
        );

        abort_unless(
            $commercePostSaleResolution->outcome
                === CommercePostSaleResolutionOutcome::CustomerCredit,
            404
        );

        try {
            $grant =
                $manager->grant(
                    $commercePostSaleResolution,
                    $request->validated(
                        'idempotency_key'
                    ),
                    $request->user()
                );
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'post_sale_outcome' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'commerce-post-sale.show',
                $commercePostSaleResolution
                    ->request
            )
            ->with(
                'success',
                'Saldo a favor materializado por $ '
                .number_format(
                    $grant->amount_minor / 100,
                    2,
                    ',',
                    '.'
                )
                .'. Su consumo permanece separado.'
            );
    }
}
