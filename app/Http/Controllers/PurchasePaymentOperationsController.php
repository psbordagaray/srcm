<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchasePaymentControlReader;
use App\Domain\Purchase\PurchasePaymentExternalVerificationReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentGroupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PurchasePaymentOperationsController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        PurchasePaymentControlReader $control,
        PurchasePaymentExternalVerificationReader $externalVerification
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $origins = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where('active', true)
            ->where(
                'type',
                '!=',
                FinancialAccountType::CashReserve->value
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'currency_code',
                'external_label',
            ]);

        $eligibleBuckets = $this->eligibleBuckets(
            $organizationId,
            $origins
        );

        $groups = PurchasePaymentGroupRequest::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party:id,name,tax_id',
                'beneficiary:id,name,tax_id',
                'originFinancialAccount:id,name,type,currency_code,active',
                'requestedBy:id,name',
                'approvedBy:id,name',
                'resolvedBy:id,name',
                'items.obligation.order:id,public_id',
                'disbursement.executedBy:id,name',
                'disbursement.cashMovement',
                'disbursement.cashRegisterSession.closure',
                'disbursement.cashRegister',
                'disbursement.allocations',
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        $disbursements = PurchasePaymentDisbursement::query()
            ->forOrganization($organizationId)
            ->with([
                'individualRequest.obligation.order:id,public_id',
                'groupRequest',
                'originFinancialAccount:id,name,type,currency_code,active',
                'beneficiary:id,name,tax_id',
                'executedBy:id,name',
                'allocations.obligation.order:id,public_id',
                'cashMovement',
                'cashRegisterSession.closure',
                'cashRegister',
                'externalVerification.financialMovement.account',
                'externalVerification.verifiedBy:id,name',
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        $controls = collect();
        $externalCandidates = collect();
        $canVerifyExternal = $request->user()->can(
            'review-financial-reconciliation'
        );

        foreach ($disbursements as $disbursement) {
            $controls->put(
                $disbursement->id,
                $control->readDisbursement(
                    $disbursement,
                    $request->user()
                )
            );

            if (
                $canVerifyExternal
                && $disbursement->channel->value
                    === 'noncash'
                && $disbursement
                    ->externalVerification === null
            ) {
                $externalCandidates->put(
                    $disbursement->id,
                    collect(
                        $externalVerification->candidates(
                            $disbursement,
                            $request->user()
                        )
                    )
                );
            }
        }

        return view('purchases.payment-operations', [
            'eligibleBuckets' => $eligibleBuckets,
            'groups' => $groups,
            'disbursements' => $disbursements,
            'controls' => $controls,
            'externalCandidates' =>
                $externalCandidates,
            'canVerifyExternal' =>
                $canVerifyExternal,
            'summary' => [
                'eligible_obligations' =>
                    $eligibleBuckets->sum(
                        fn (array $bucket): int =>
                            $bucket['obligations']->count()
                    ),
                'active_groups' =>
                    $groups->filter(
                        fn ($group): bool =>
                            $group->status->isActive()
                    )->count(),
                'canonical_disbursements' =>
                    $disbursements->count(),
            ],
        ]);
    }

    /**
     * @return Collection<int,array{
     *   supplier:mixed,
     *   beneficiary:mixed,
     *   currency_code:string,
     *   origins:Collection,
     *   obligations:Collection
     * }>
     */
    private function eligibleBuckets(
        int $organizationId,
        Collection $origins
    ): Collection {
        $obligations = PurchaseObligation::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party:id,name,tax_id',
                'beneficiary:id,name,tax_id',
                'order:id,public_id',
                'paymentRequests:id,purchase_obligation_id,status',
                'paymentGroupItems:id,purchase_obligation_id,purchase_payment_group_request_id',
                'paymentGroupItems.request:id,status',
            ])
            ->withSum(
                'paymentExecutions as legacy_execution_minor',
                'amount_minor'
            )
            ->withSum(
                'paymentDisbursementAllocations as disbursement_execution_minor',
                'amount_minor'
            )
            ->withSum(
                'supplierCreditApplications as supplier_credit_minor',
                'amount_minor'
            )
            ->withSum(
                'supplierAdvanceApplications as supplier_advance_minor',
                'amount_minor'
            )
            ->latest('id')
            ->limit(250)
            ->get();

        $buckets = collect();

        foreach ($obligations as $obligation) {
            $settledMinor =
                (int) $obligation->legacy_execution_minor
                + (int) $obligation->disbursement_execution_minor
                + (int) $obligation->supplier_credit_minor
                + (int) $obligation->supplier_advance_minor;
            $remainingMinor = max(
                0,
                (int) $obligation->amount_minor - $settledMinor
            );
            $hasActiveIndividual = $obligation
                ->paymentRequests
                ->contains(
                    fn ($paymentRequest): bool =>
                        $paymentRequest->status->isActive()
                );
            $hasActiveGroup = $obligation
                ->paymentGroupItems
                ->contains(
                    fn ($item): bool =>
                        $item->request?->status->isActive()
                        ?? false
                );
            $compatibleOrigins = $origins->where(
                'currency_code',
                $obligation->currency_code
            )->values();

            if (
                $remainingMinor <= 0
                || $hasActiveIndividual
                || $hasActiveGroup
                || $compatibleOrigins->isEmpty()
            ) {
                continue;
            }

            $key = implode(':', [
                (int) $obligation->supplier_id,
                (int) $obligation
                    ->beneficiary_business_party_id,
                (string) $obligation->currency_code,
            ]);

            if (! $buckets->has($key)) {
                $buckets->put($key, [
                    'supplier' => $obligation->supplier,
                    'beneficiary' => $obligation->beneficiary,
                    'currency_code' =>
                        (string) $obligation->currency_code,
                    'origins' => $compatibleOrigins,
                    'obligations' => collect(),
                ]);
            }

            $bucket = $buckets->get($key);
            $bucket['obligations']->push([
                'model' => $obligation,
                'remaining_minor' => $remainingMinor,
            ]);
            $buckets->put($key, $bucket);
        }

        return $buckets
            ->filter(
                fn (array $bucket): bool =>
                    $bucket['obligations']->count() >= 2
            )
            ->values();
    }
}
