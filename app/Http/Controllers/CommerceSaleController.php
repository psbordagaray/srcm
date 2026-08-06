<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Http\Requests\StoreCommerceSaleRequest;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\InventoryLocation;
use App\Models\ServiceOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommerceSaleController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $search = Str::of((string) $request->query('search'))
            ->squish()
            ->toString();

        $sales = CommerceSale::query()
            ->forOrganization($organizationId)
            ->with([
                'customer',
                'serviceOrder.asset',
                'payments',
                'recordedBy',
            ])
            ->when($search !== '', function (Builder $query) use (
                $search
            ): void {
                $query->where(function (Builder $match) use (
                    $search
                ): void {
                    if (ctype_digit($search)) {
                        $match->orWhere('sale_number', (int) $search);
                    }

                    $match
                        ->orWhere(
                            'customer_name_snapshot',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'customer_document_snapshot',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere('public_id', 'like', "%{$search}%")
                        ->orWhereHas(
                            'serviceOrder',
                            function (Builder $order) use ($search): void {
                                if (ctype_digit($search)) {
                                    $order->where(
                                        'order_number',
                                        (int) $search
                                    );
                                } else {
                                    $order
                                        ->where(
                                            'public_id',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhereHas(
                                            'asset',
                                            fn (Builder $asset): Builder => $asset
                                                ->where(
                                                    'brand_name',
                                                    'like',
                                                    "%{$search}%"
                                                )
                                                ->orWhere(
                                                    'model_name',
                                                    'like',
                                                    "%{$search}%"
                                                )
                                        );
                                }
                            }
                        );
                });
            })
            ->latest('sold_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('commerce-sales.index', [
            'sales' => $sales,
            'search' => $search,
            'summary' => [
                'confirmed' => CommerceSale::query()
                    ->forOrganization($organizationId)
                    ->count(),
                'total_minor' => (int) CommerceSale::query()
                    ->forOrganization($organizationId)
                    ->sum('total_minor'),
                'unsettled_services' => ServiceOrder::query()
                    ->forOrganization($organizationId)
                    ->unsettledDelivered()
                    ->count(),
            ],
        ]);
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $unsettledOrders = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->unsettledDelivered()
            ->with([
                'asset',
                'customer',
                'owner',
                'delivery',
                'quotes.decision.selectedOption.lines',
            ])
            ->latest('order_number')
            ->get();

        $selectedPublicId = Str::of(
            (string) $request->query('service_order')
        )->trim()->toString();
        $selectedServiceOrder = $selectedPublicId === ''
            ? null
            : $unsettledOrders->firstWhere(
                'public_id',
                $selectedPublicId
            );

        if ($selectedPublicId !== '' && ! $selectedServiceOrder) {
            abort(404);
        }

        return view('commerce-sales.create', [
            'unsettledOrders' => $unsettledOrders,
            'selectedServiceOrder' => $selectedServiceOrder,
            'customers' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'tax_id']),
            'products' => CatalogProduct::query()
                ->where('active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'sku',
                    'name',
                    'base_unit_code',
                    'quantity_scale',
                ]),
            'locations' => InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'paymentMethods' => CommercePaymentMethod::cases(),
            'conditions' => InventoryCondition::cases(),
            'idempotencyKey' => 'service-ui:commerce-sale:'.Str::uuid(),
        ]);
    }

    public function store(
        StoreCommerceSaleRequest $request,
        CommerceCheckoutManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $sale = $manager->checkout(
                new CommerceCheckoutData(
                    currencyCode: $validated['currency_code'],
                    idempotencyKey: $validated['idempotency_key'],
                    payments: collect($validated['payments'])
                        ->map(
                            fn (array $payment): CommercePaymentData => new CommercePaymentData(
                                method: CommercePaymentMethod::from(
                                    $payment['method']
                                ),
                                amountMinor: $this->moneyMinor(
                                    $payment['amount']
                                ),
                                reference: $payment['reference'] ?? null,
                                notes: $payment['notes'] ?? null,
                                paidAt: filled(
                                    $payment['paid_at'] ?? null
                                )
                                        ? CarbonImmutable::parse(
                                            $payment['paid_at'],
                                            config('app.timezone')
                                        )
                                        : null
                            )
                        )
                        ->values()
                        ->all(),
                    productLines: collect(
                        $validated['product_lines'] ?? []
                    )
                        ->map(
                            fn (array $line): CommerceProductLineData => new CommerceProductLineData(
                                catalogProductId: $line['catalog_product_id'],
                                sourceLocationId: $line['source_location_id'],
                                condition: InventoryCondition::from(
                                    $line['condition']
                                ),
                                quantity: $line['quantity'],
                                unitPriceMinor: $this->moneyMinor(
                                    $line['unit_price']
                                )
                            )
                        )
                        ->values()
                        ->all(),
                    serviceOrderId: $validated['service_order_id'] ?? null,
                    customerBusinessPartyId: $validated[
                            'customer_business_party_id'
                        ] ?? null,
                    customerName: $validated['customer_name'] ?? null,
                    customerDocument: $validated['customer_document'] ?? null,
                    notes: $validated['notes'] ?? null,
                    soldAt: filled($validated['sold_at'] ?? null)
                        ? CarbonImmutable::parse(
                            $validated['sold_at'],
                            config('app.timezone')
                        )
                        : null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'commerce' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('commerce-sales.show', $sale)
            ->with(
                'success',
                "Venta #{$sale->sale_number} confirmada con cobro exacto."
            );
    }

    public function show(
        Request $request,
        CommerceSale $commerceSale,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $commerceSale->organization_id === $organizationId,
            404
        );

        $commerceSale->load([
            'customer',
            'recordedBy',
            'serviceOrder.asset',
            'serviceOrder.delivery',
            'delivery',
            'quoteDecision',
            'quoteOption.lines',
            'lines.quoteLine',
            'lines.product',
            'lines.inventoryMovementLine',
            'payments.receivedBy',
            'inventoryMovement.lines.product',
            'inventoryMovement.lines.sourceLocation',
        ]);

        return view('commerce-sales.show', [
            'sale' => $commerceSale,
        ]);
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of(
            str_replace(',', '.', trim($value))
        )
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
