<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleExchangeSelectionData;
use App\Domain\Commerce\CommercePostSaleExchangeSelectionLineData;
use App\Domain\Commerce\CommercePostSaleExchangeSelectionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Http\Requests\StoreCommercePostSaleExchangeSelection;
use App\Models\CommercePostSaleResolution;
use App\Models\OrganizationProductPrice;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleExchangeSelectionController extends Controller
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
            'exchangeSelection.lines.product',
            'exchangeSelection.lines.price',
            'exchangeSelection.execution',
        ]);

        abort_unless(
            $commercePostSaleResolution->outcome
                === CommercePostSaleResolutionOutcome::Exchange,
            404
        );

        $prices =
            OrganizationProductPrice::query()
                ->forOrganization(
                    $organizationId
                )
                ->where(
                    'currency_code',
                    $commercePostSaleResolution
                        ->currency_code
                )
                ->where(
                    'is_current',
                    true
                )
                ->whereHas(
                    'product',
                    fn ($query) =>
                        $query->where(
                            'active',
                            true
                        )
                )
                ->with('product')
                ->get()
                ->sortBy(
                    fn (
                        OrganizationProductPrice $price
                    ): string =>
                        mb_strtolower(
                            (string) $price
                                ->product
                                ?->name
                        )
                )
                ->values();

        return view(
            'commerce-post-sale.exchange-selection-create',
            [
                'resolution' =>
                    $commercePostSaleResolution,
                'prices' => $prices,
                'idempotencyKey' =>
                    'ui:commerce-post-sale-exchange-selection:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        StoreCommercePostSaleExchangeSelection $request,
        CommercePostSaleResolution $commercePostSaleResolution,
        CommercePostSaleExchangeSelectionManager $manager,
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
            $selection =
                $manager->select(
                    $commercePostSaleResolution,
                    new CommercePostSaleExchangeSelectionData(
                        lines:
                            collect(
                                $validated['lines']
                            )
                                ->map(
                                    fn (
                                        array $line
                                    ): CommercePostSaleExchangeSelectionLineData =>
                                        new CommercePostSaleExchangeSelectionLineData(
                                            catalogProductId:
                                                (int) $line[
                                                    'catalog_product_id'
                                                ],
                                            quantity:
                                                (string) $line[
                                                    'quantity'
                                                ]
                                        )
                                )
                                ->values()
                                ->all(),
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
                'Reemplazo seleccionado por $ '
                .number_format(
                    $selection
                        ->replacementAmountMinor()
                        / 100,
                    2,
                    ',',
                    '.'
                )
                .'. La entrega y diferencia permanecen pendientes.'
            );
    }
}
