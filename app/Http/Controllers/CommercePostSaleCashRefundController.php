<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleCashRefundManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Http\Requests\ExecuteCommercePostSaleCashRefund;
use App\Models\CommercePostSaleResolution;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleCashRefundController extends Controller
{
    public function create(
        Request $request,
        CommercePostSaleResolution $commercePostSaleResolution,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commercePostSaleResolution
                ->organization_id
                === $organizationId,
            404
        );

        $commercePostSaleResolution->load([
            'request.sale',
            'resolvedBy',
            'preferredOriginalPayment.financialAccount',
            'cashRefundExecution.cashMovement',
        ]);

        abort_unless(
            $commercePostSaleResolution->outcome
                === CommercePostSaleResolutionOutcome::Refund
            && $commercePostSaleResolution
                ->preferredOriginalPayment
                ?->method
                === CommercePaymentMethod::Cash,
            404
        );

        return view(
            'commerce-post-sale.cash-refund-create',
            [
                'resolution' =>
                    $commercePostSaleResolution,
                'idempotencyKey' =>
                    'ui:commerce-post-sale-cash-refund:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        ExecuteCommercePostSaleCashRefund $request,
        CommercePostSaleResolution $commercePostSaleResolution,
        CommercePostSaleCashRefundManager $manager,
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

        $validated = $request->validated();

        try {
            $execution =
                $manager->execute(
                    $commercePostSaleResolution,
                    $validated[
                        'execution_reference'
                    ] ?? null,
                    $validated[
                        'execution_note'
                    ] ?? null,
                    $validated[
                        'idempotency_key'
                    ],
                    $request->user()
                );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
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
                'Reembolso en efectivo ejecutado por $ '
                .number_format(
                    $execution->amount_minor / 100,
                    2,
                    ',',
                    '.'
                )
                .'.'
            );
    }
}
