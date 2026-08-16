<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleReceiptData;
use App\Domain\Commerce\CommercePostSaleReceiptLineData;
use App\Domain\Commerce\CommercePostSaleReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Http\Requests\StoreCommercePostSaleReceipt;
use App\Models\CommercePostSaleReceiptLine;
use App\Models\CommercePostSaleRequest;
use App\Models\InventoryLocation;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleReceiptController extends Controller
{
    public function create(
        Request $request,
        CommercePostSaleRequest $commercePostSaleRequest,
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
            'sale',
            'lines.saleLine.product',
            'receipts.lines',
        ]);

        $requestLineIds =
            $commercePostSaleRequest
                ->lines
                ->pluck('id')
                ->all();

        $receivedByLine =
            CommercePostSaleReceiptLine::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->whereIn(
                    'commerce_post_sale_request_line_id',
                    $requestLineIds
                )
                ->get()
                ->groupBy(
                    'commerce_post_sale_request_line_id'
                );

        $receivableLines =
            $commercePostSaleRequest
                ->lines
                ->map(function (
                    $line
                ) use ($receivedByLine): array {
                    $received =
                        BigDecimal::zero();

                    foreach (
                        $receivedByLine->get(
                            $line->id,
                            collect()
                        ) as $receiptLine
                    ) {
                        $received =
                            $received->plus(
                                BigDecimal::of(
                                    (string) $receiptLine
                                        ->quantity
                                )
                            );
                    }

                    $requested =
                        BigDecimal::of(
                            (string) $line->quantity
                        );

                    $remaining =
                        $requested->minus($received);

                    return [
                        'line' => $line,
                        'requested' =>
                            (string) $requested,
                        'received' =>
                            (string) $received,
                        'remaining' =>
                            (string) $remaining,
                        'complete' =>
                            ! $remaining
                                ->isGreaterThan(
                                    BigDecimal::zero()
                                ),
                    ];
                })
                ->values();

        $locations =
            InventoryLocation::query()
                ->forOrganization(
                    $organizationId
                )
                ->where('active', true)
                ->orderBy('name')
                ->orderBy('id')
                ->get();

        return view(
            'commerce-post-sale.receipt-create',
            [
                'case' =>
                    $commercePostSaleRequest,
                'receivableLines' =>
                    $receivableLines,
                'locations' =>
                    $locations,
                'conditions' =>
                    InventoryCondition::cases(),
                'idempotencyKey' =>
                    'ui:commerce-post-sale-receipt:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        StoreCommercePostSaleReceipt $request,
        CommercePostSaleRequest $commercePostSaleRequest,
        CommercePostSaleReceiptManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organizationId =
            $currentOrganization->id($request->user());

        abort_unless(
            (int) $commercePostSaleRequest
                ->organization_id
                === $organizationId,
            404
        );

        $validated =
            $request->validated();

        try {
            $receipt =
                $manager->receive(
                    new CommercePostSaleReceiptData(
                        commercePostSaleRequestId:
                            $commercePostSaleRequest->id,
                        lines:
                            collect(
                                $validated['lines']
                            )
                                ->map(
                                    fn (
                                        array $line
                                    ): CommercePostSaleReceiptLineData =>
                                        new CommercePostSaleReceiptLineData(
                                            commercePostSaleRequestLineId:
                                                (int) $line[
                                                    'commerce_post_sale_request_line_id'
                                                ],
                                            quantity:
                                                (string) $line[
                                                    'quantity'
                                                ],
                                            condition:
                                                InventoryCondition::from(
                                                    $line[
                                                        'condition'
                                                    ]
                                                ),
                                            destinationLocationId:
                                                (int) $line[
                                                    'destination_location_id'
                                                ],
                                            notes:
                                                $line['notes']
                                                    ?? null
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
                    'post_sale_receipt' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route(
                'commerce-post-sale.show',
                $commercePostSaleRequest
            )
            ->with(
                'success',
                'Recepción física '
                .Str::limit(
                    $receipt->public_id,
                    12
                )
                .' confirmada y registrada en inventario.'
            );
    }
}
