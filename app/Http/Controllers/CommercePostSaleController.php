<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleRequestData;
use App\Domain\Commerce\CommercePostSaleRequestLineData;
use App\Domain\Commerce\CommercePostSaleRequestManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleIntent;
use App\Enums\CommerceSaleLineType;
use App\Enums\CommerceSaleStatus;
use App\Http\Requests\StoreCommercePostSaleRequest;
use App\Models\CommercePostSaleRequest as PostSaleRequestModel;
use App\Models\CommerceSale;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId =
            $currentOrganization->id($request->user());

        $search = Str::of(
            (string) $request->query('search')
        )->squish()->toString();

        $intentValue = Str::of(
            (string) $request->query('intent')
        )->trim()->lower()->toString();

        $intent = $intentValue === ''
            ? null
            : CommercePostSaleIntent::tryFrom(
                $intentValue
            );

        $cases = PostSaleRequestModel::query()
            ->forOrganization($organizationId)
            ->with([
                'sale',
                'requestedBy',
            ])
            ->withCount([
                'receipts',
                'resolutions',
            ])
            ->when(
                $intent instanceof CommercePostSaleIntent,
                fn (Builder $query): Builder =>
                    $query->where(
                        'intent',
                        $intent->value
                    )
            )
            ->when(
                $search !== '',
                function (Builder $query) use (
                    $search
                ): void {
                    $query->where(
                        function (
                            Builder $match
                        ) use ($search): void {
                            $match
                                ->where(
                                    'public_id',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'reason',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'sale',
                                    function (
                                        Builder $sale
                                    ) use ($search): void {
                                        if (
                                            ctype_digit(
                                                $search
                                            )
                                        ) {
                                            $sale->orWhere(
                                                'sale_number',
                                                (int) $search
                                            );
                                        }

                                        $sale
                                            ->orWhere(
                                                'public_id',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'customer_name_snapshot',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'customer_document_snapshot',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                );
                        }
                    );
                }
            )
            ->latest('requested_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view(
            'commerce-post-sale.index',
            [
                'cases' => $cases,
                'search' => $search,
                'selectedIntent' =>
                    $intent?->value,
                'intents' =>
                    CommercePostSaleIntent::cases(),
            ]
        );
    }

    public function create(
        Request $request,
        CommerceSale $commerceSale,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commerceSale->organization_id
                === $organizationId,
            404
        );

        abort_unless(
            $commerceSale->status
                === CommerceSaleStatus::Confirmed,
            404
        );

        $commerceSale->load([
            'lines.product',
            'postSaleRequests.requestedBy',
        ]);

        $productLines =
            $commerceSale->lines
                ->filter(
                    fn ($line): bool =>
                        $line->line_type
                            === CommerceSaleLineType::Product
                        && $line->catalog_product_id
                            !== null
                )
                ->values();

        return view(
            'commerce-post-sale.create',
            [
                'sale' => $commerceSale,
                'productLines' =>
                    $productLines,
                'intents' =>
                    CommercePostSaleIntent::cases(),
                'idempotencyKey' =>
                    'ui:commerce-post-sale:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        StoreCommercePostSaleRequest $request,
        CommerceSale $commerceSale,
        CommercePostSaleRequestManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commerceSale->organization_id
                === $organizationId,
            404
        );

        $validated = $request->validated();

        try {
            $postSale =
                $manager->create(
                    new CommercePostSaleRequestData(
                        commerceSaleId:
                            $commerceSale->id,
                        intent:
                            CommercePostSaleIntent::from(
                                $validated['intent']
                            ),
                        lines:
                            collect(
                                $validated['lines']
                            )
                                ->map(
                                    fn (
                                        array $line
                                    ): CommercePostSaleRequestLineData =>
                                        new CommercePostSaleRequestLineData(
                                            commerceSaleLineId:
                                                (int) $line[
                                                    'commerce_sale_line_id'
                                                ],
                                            quantity:
                                                (string) $line[
                                                    'quantity'
                                                ]
                                        )
                                )
                                ->values()
                                ->all(),
                        reason:
                            $validated['reason'],
                        idempotencyKey:
                            $validated[
                                'idempotency_key'
                            ],
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
                    'post_sale' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'commerce-post-sale.show',
                $postSale
            )
            ->with(
                'success',
                'Solicitud de posventa registrada como hecho inmutable.'
            );
    }

    public function show(
        Request $request,
        PostSaleRequestModel $commercePostSaleRequest,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commercePostSaleRequest
                ->organization_id
                === $organizationId,
            404
        );

        $commercePostSaleRequest->load([
            'sale.customer',
            'requestedBy',
            'lines.saleLine.product',
            'receipts.receivedBy',
            'receipts.inventoryMovement',
            'receipts.lines.requestLine.saleLine.product',
            'receipts.lines.destinationLocation',
            'resolutions.resolvedBy',
            'resolutions.preferredOriginalPayment.financialAccount',
            'resolutions.lines.receiptLine.requestLine.saleLine.product',
            'resolutions.customerCreditGrant.party',
            'resolutions.cashRefundExecution.cashMovement',
            'resolutions.externalRefundInstruction.requestedBy',
            'resolutions.externalRefundInstruction.providerConnection',
            'resolutions.externalRefundInstruction.dispatch.evidence.financialMovement',
            'resolutions.exchangeSelection.lines.product',
            'resolutions.exchangeSelection.lines.price',
            'resolutions.exchangeSelection.selectedBy',
            'resolutions.exchangeSelection.execution.executedBy',
            'resolutions.exchangeSelection.execution.inventoryMovement',
            'resolutions.exchangeSelection.execution.payments.financialAccount',
            'resolutions.exchangeSelection.execution.creditGrant.party',
        ]);

        return view(
            'commerce-post-sale.show',
            [
                'case' =>
                    $commercePostSaleRequest,
            ]
        );
    }
}
