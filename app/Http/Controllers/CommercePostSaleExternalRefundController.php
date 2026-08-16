<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleExternalRefundInstructionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Http\Requests\RequestCommercePostSaleExternalRefund;
use App\Models\CommercePostSaleResolution;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CommercePostSaleExternalRefundController extends Controller
{
    public function store(
        RequestCommercePostSaleExternalRefund $request,
        CommercePostSaleResolution $commercePostSaleResolution,
        CommercePostSaleExternalRefundInstructionManager $manager,
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
                === CommercePostSaleResolutionOutcome::Refund,
            404
        );

        try {
            $instruction =
                $manager->request(
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
                'Instrucción de reembolso externo '
                .substr(
                    $instruction->public_id,
                    0,
                    12
                )
                .' creada. Aún no fue enviada al proveedor.'
            );
    }
}
