<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleExternalRefundSubmissionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\SubmitCommercePostSaleExternalRefund;
use App\Models\CommercePostSaleExternalRefundInstruction;
use DomainException;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class CommercePostSaleExternalRefundSubmissionController extends Controller
{
    public function store(
        SubmitCommercePostSaleExternalRefund $request,
        CommercePostSaleExternalRefundInstruction $externalRefundInstruction,
        CommercePostSaleExternalRefundSubmissionManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $externalRefundInstruction
                ->organization_id
                === $organizationId,
            404
        );

        $externalRefundInstruction->load([
            'resolution.request',
            'dispatch.evidence.financialMovement',
        ]);

        try {
            $evidence =
                $manager->submit(
                    $externalRefundInstruction
                );
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'post_sale_execution' =>
                        $exception->getMessage(),
                ]);
        } catch (RuntimeException) {
            return back()
                ->withErrors([
                    'post_sale_execution' =>
                        'El proveedor no confirmó el resultado del reembolso. '
                        .'El despacho idempotente se conserva; reintentá esta misma instrucción.'
                ]);
        }

        return redirect()
            ->route(
                'commerce-post-sale.show',
                $externalRefundInstruction
                    ->resolution
                    ->request
            )
            ->with(
                'success',
                'Evidencia de reembolso externo registrada con estado '
                .$evidence
                    ->financialMovement
                    ->status
                    ->value
                .'.'
            );
    }
}
