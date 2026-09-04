<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommerceSettlementReviewResolutionData;
use App\Domain\Commerce\CommerceSettlementReviewResolutionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSettlementReviewResolutionOutcome;
use App\Http\Requests\StoreCommerceSettlementReviewResolution;
use App\Models\CommerceSettlementReview;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CommerceSettlementReviewResolutionController extends Controller
{
    public function store(
        StoreCommerceSettlementReviewResolution $request,
        CommerceSettlementReview $commerceSettlementReview,
        CommerceSettlementReviewResolutionManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        abort_unless(
            (int) $commerceSettlementReview->organization_id
                === $organizationId,
            404
        );

        $validated = $request->validated();

        try {
            $manager->resolve(
                new CommerceSettlementReviewResolutionData(
                    commerceSettlementReviewId:
                        $commerceSettlementReview->id,
                    outcome:
                        CommerceSettlementReviewResolutionOutcome::from(
                            $validated['outcome']
                        ),
                    reason: $validated['reason'],
                    notes: $validated['notes'] ?? null,
                    idempotencyKey:
                        $validated['idempotency_key'],
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'settlement_review_resolution' =>
                        $exception->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            'Resolución de revisión de liquidación registrada. '
            .'La ejecución del outcome permanece separada.'
        );
    }
}
