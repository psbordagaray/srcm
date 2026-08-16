<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommercePostSaleResolutionData;
use App\Domain\Commerce\CommercePostSaleResolutionLineData;
use App\Domain\Commerce\CommercePostSaleResolutionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Http\Requests\StoreCommercePostSaleResolution;
use App\Models\CommercePostSaleRequest;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercePostSaleResolutionController extends Controller
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
            'sale.payments.financialAccount',
            'receipts.lines.requestLine.saleLine.product',
            'receipts.lines.resolutionLines',
        ]);

        $resolvableLines =
            $commercePostSaleRequest
                ->receipts
                ->flatMap(
                    function ($receipt) {
                        return $receipt->lines
                            ->map(
                                function (
                                    $line
                                ) use ($receipt): array {
                                    $resolved =
                                        BigDecimal::zero();

                                    foreach (
                                        $line->resolutionLines
                                        as $resolutionLine
                                    ) {
                                        $resolved =
                                            $resolved->plus(
                                                BigDecimal::of(
                                                    (string) $resolutionLine
                                                        ->quantity
                                                )
                                            );
                                    }

                                    $received =
                                        BigDecimal::of(
                                            (string) $line->quantity
                                        );

                                    $remaining =
                                        $received->minus(
                                            $resolved
                                        );

                                    return [
                                        'receipt' =>
                                            $receipt,
                                        'line' =>
                                            $line,
                                        'received' =>
                                            (string) $received,
                                        'resolved' =>
                                            (string) $resolved,
                                        'remaining' =>
                                            (string) $remaining,
                                        'complete' =>
                                            ! $remaining
                                                ->isGreaterThan(
                                                    BigDecimal::zero()
                                                ),
                                    ];
                                }
                            );
                    }
                )
                ->values();

        return view(
            'commerce-post-sale.resolution-create',
            [
                'case' =>
                    $commercePostSaleRequest,
                'resolvableLines' =>
                    $resolvableLines,
                'outcomes' =>
                    CommercePostSaleResolutionOutcome::cases(),
                'payments' =>
                    $commercePostSaleRequest
                        ->sale
                        ->payments,
                'idempotencyKey' =>
                    'ui:commerce-post-sale-resolution:'
                    .Str::uuid(),
            ]
        );
    }

    public function store(
        StoreCommercePostSaleResolution $request,
        CommercePostSaleRequest $commercePostSaleRequest,
        CommercePostSaleResolutionManager $manager,
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
            $resolution =
                $manager->resolve(
                    new CommercePostSaleResolutionData(
                        commercePostSaleRequestId:
                            $commercePostSaleRequest->id,
                        outcome:
                            CommercePostSaleResolutionOutcome::from(
                                $validated['outcome']
                            ),
                        lines:
                            collect(
                                $validated['lines']
                            )
                                ->map(
                                    fn (
                                        array $line
                                    ): CommercePostSaleResolutionLineData =>
                                        new CommercePostSaleResolutionLineData(
                                            commercePostSaleReceiptLineId:
                                                (int) $line[
                                                    'commerce_post_sale_receipt_line_id'
                                                ],
                                            quantity:
                                                (string) $line[
                                                    'quantity'
                                                ],
                                            recognizedAmountMinor:
                                                $this->moneyToMinor(
                                                    (string) $line[
                                                        'recognized_amount'
                                                    ]
                                                ),
                                            adjustmentReason:
                                                $line[
                                                    'adjustment_reason'
                                                ] ?? null
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
                        preferredOriginalPaymentId:
                            isset(
                                $validated[
                                    'preferred_original_payment_id'
                                ]
                            )
                                ? (int) $validated[
                                    'preferred_original_payment_id'
                                ]
                                : null,
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
                    'post_sale_resolution' =>
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
                'Resolución económica '
                .Str::limit(
                    $resolution->public_id,
                    12
                )
                .' registrada. La ejecución del outcome permanece separada.'
            );
    }

    private function moneyToMinor(
        string $value
    ): int {
        $normalized =
            str_replace(
                ',',
                '.',
                trim($value)
            );

        if (
            ! preg_match(
                '/^(\d{1,12})(?:\.(\d{1,2}))?$/',
                $normalized,
                $matches
            )
        ) {
            throw new DomainException(
                'El valor reconocido no posee un formato monetario válido.'
            );
        }

        $whole = (int) $matches[1];
        $fraction =
            str_pad(
                $matches[2] ?? '',
                2,
                '0'
            );

        return ($whole * 100)
            + (int) $fraction;
    }
}
