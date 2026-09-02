<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommerceSalePolicyGuard;
use App\Domain\Commerce\OrganizationProductPriceReader;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerCreditBalanceReader;
use App\Domain\Commerce\CustomerCreditExposureReader;
use App\Domain\Numerics\AuthoritativeNumericInput;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Http\Requests\StoreCommerceSaleRequest;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\ServiceOrder;
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
        CurrentOrganization $currentOrganization,
        OrganizationProductPriceReader $priceReader,
        CommerceSalePolicyGuard $salePolicy,
        CashRegisterSessionManager $cashSessions,
        CustomerCreditBalanceReader $creditBalances,
        CustomerCreditExposureReader $creditExposure
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

        $products = CatalogProduct::query()
            ->where('active', true)
            ->with([
                'productCategory',
                'brand',
                'manufacturer',
                'knowledgeEntity.identifiers.identifierType',
                'knowledgeEntity.outgoingCompatibilities.rightEntity.identifiers.identifierType',
                'knowledgeEntity.incomingCompatibilities.leftEntity.identifiers.identifierType',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'product_category_id',
                'brand_id',
                'manufacturer_id',
                'knowledge_entity_id',
                'knowledge_identifier_id',
                'sku',
                'name',
                'description',
                'base_unit_code',
                'quantity_scale',
            ]);

        $productSearchIndex = $products
            ->map(function (CatalogProduct $product): array {
                $terms = collect();

                $pushTerm = function (
                    mixed $value,
                    string $kind,
                    bool $exact = false
                ) use ($terms): void {
                    $text = Str::of((string) ($value ?? ''))
                        ->squish()
                        ->toString();

                    if ($text === '') {
                        return;
                    }

                    $terms->push([
                        'value' => $text,
                        'kind' => $kind,
                        'exact' => $exact,
                    ]);
                };

                $pushTerm($product->sku, 'SKU', true);
                $pushTerm($product->name, 'Artículo');
                $pushTerm($product->description, 'Descripción');
                $pushTerm(
                    $product->productCategory?->name,
                    'Categoría'
                );
                $pushTerm($product->brand?->name, 'Marca');
                $pushTerm(
                    $product->manufacturer?->name,
                    'Fabricante'
                );

                $knowledgeEntity = $product->knowledgeEntity;

                if ($knowledgeEntity?->active) {
                    $pushTerm(
                        $knowledgeEntity->name,
                        'Ficha de conocimiento'
                    );

                    foreach (
                        $knowledgeEntity->identifiers
                            ->where('active', true)
                        as $identifier
                    ) {
                        $pushTerm(
                            $identifier->value,
                            $identifier->identifierType?->name
                                ?? 'Identificador',
                            true
                        );
                    }

                    $relatedEntities = $knowledgeEntity
                        ->outgoingCompatibilities
                        ->where('active', true)
                        ->pluck('rightEntity')
                        ->merge(
                            $knowledgeEntity
                                ->incomingCompatibilities
                                ->where('active', true)
                                ->pluck('leftEntity')
                        )
                        ->filter(
                            fn ($entity): bool =>
                                (bool) $entity?->active
                        )
                        ->unique('id')
                        ->values();

                    foreach ($relatedEntities as $relatedEntity) {
                        $pushTerm(
                            $relatedEntity->name,
                            'Modelo / relación'
                        );

                        foreach (
                            $relatedEntity->identifiers
                                ->where('active', true)
                            as $identifier
                        ) {
                            $pushTerm(
                                $identifier->value,
                                $identifier->identifierType?->name
                                    ?? 'Código relacionado',
                                true
                            );
                        }
                    }
                }

                return [
                    'id' => (int) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->base_unit_code,
                    'scale' => (int) $product->quantity_scale,
                    'terms' => $terms
                        ->unique(
                            fn (array $term): string =>
                                Str::lower(
                                    $term['kind'].'|'.$term['value']
                                )
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $customers = BusinessParty::query()
            ->forOrganization($organizationId)
            ->whereHas(
                'customer',
                fn (Builder $customer): Builder =>
                    $customer->where('active', true)
            )
            ->orderBy('name')
            ->get(['id', 'name', 'tax_id']);

        return view('commerce-sales.create', [
            'unsettledOrders' => $unsettledOrders,
            'selectedServiceOrder' => $selectedServiceOrder,
            'customers' => $customers,
            'products' => $products,
            'productSearchIndex' => $productSearchIndex,
            'locations' => InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'paymentMethods' => CommercePaymentMethod::cases(),
            'customerCreditBalances' =>
                $creditBalances->matrixForOrganization(
                    $organizationId
                ),
            'customerCreditPolicyMatrix' =>
                $creditExposure->matrixForParties(
                    $customers,
                    $request->user()
                ),
            'activeCashSession' => $cashSessions->currentFor(
                $request->user()
            ),
            'financialAccounts' => FinancialAccount::query()
                ->forOrganization($organizationId)
                ->where('active', true)
                ->orderBy('currency_code')
                ->orderBy('name')
                ->get([
                    'id',
                    'public_id',
                    'name',
                    'type',
                    'provider',
                    'currency_code',
                ]),
            'conditions' => InventoryCondition::cases(),
            'productPrices' => $priceReader->matrix(
                $priceReader->currentForProducts($organizationId)
            ),
            'availabilityMatrix' =>
                $salePolicy->availabilityMatrix($request->user()),
            'canCreateCustomerReceivable' =>
                $request->user()->can(
                    'create-customer-receivables'
                ),
            'canOverrideCustomerCredit' =>
                $request->user()->can(
                    'override-customer-credit'
                ),
            'idempotencyKey' => 'service-ui:commerce-sale:'.Str::uuid(),
        ]);
    }

    public function store(
        StoreCommerceSaleRequest $request,
        CommerceCheckoutManager $manager,
        CommerceSalePolicyGuard $salePolicy
    ): RedirectResponse {
        $validated = $request->validated();
        $validatedProductLines =
            $validated['product_lines'] ?? [];

        try {
            $stockShortage = $salePolicy->stockShortageMessage(
                $validatedProductLines,
                $request->user()
            );

            if ($stockShortage !== null) {
                throw new DomainException($stockShortage);
            }

            $soldAt = CarbonImmutable::now();

            $sale = $manager->checkout(
                new CommerceCheckoutData(
                    currencyCode: $validated['currency_code'],
                    idempotencyKey: $validated['idempotency_key'],
                    payments: collect(
                        $validated['payments'] ?? []
                    )
                        ->map(
                            fn (
                                array $payment,
                                int $index
                            ): CommercePaymentData => new CommercePaymentData(
                                method: CommercePaymentMethod::from(
                                    $payment['method']
                                ),
                                amountMinor: $this->requiredMoneyMinor(
                                    $request->paymentAmountAuthoritativeInput(
                                        $index
                                    )
                                ),
                                reference: $payment['reference'] ?? null,
                                notes: $payment['notes'] ?? null,
                                paidAt: null,
                                cardBrand: $payment['card_brand'] ?? null,
                                cardNetwork: $payment['card_network'] ?? null,
                                cardLast4: $payment['card_last4'] ?? null,
                                installments: filled(
                                    $payment['installments'] ?? null
                                )
                                    ? (int) $payment['installments']
                                    : null,
                                processor: $payment['processor'] ?? null,
                                externalOperationId:
                                    $payment['external_operation_id'] ?? null,
                                authorizationCode:
                                    $payment['authorization_code'] ?? null,
                                providerStatus:
                                    $payment['provider_status'] ?? null,
                                financialAccountId:
                                    filled(
                                        $payment[
                                            'financial_account_id'
                                        ] ?? null
                                    )
                                        ? (int) $payment[
                                            'financial_account_id'
                                        ]
                                        : null,
                                tenderedAmountMinor:
                                    $this->optionalMoneyMinor(
                                        $request
                                            ->paymentTenderedAmountAuthoritativeInput(
                                                $index
                                            )
                                    )
                            )
                        )
                        ->values()
                        ->all(),
                    receivableAmountMinor:
                        $this->optionalMoneyMinor(
                            $request->receivableAmountAuthoritativeInput()
                        ),
                    receivableDueOn: filled(
                        $validated['receivable_due_on'] ?? null
                    )
                        ? CarbonImmutable::parse(
                            $validated['receivable_due_on']
                        )
                        : null,
                    productLines: $salePolicy->productLines(
                        $validatedProductLines
                    ),
                    serviceOrderId: $validated['service_order_id'] ?? null,
                    customerBusinessPartyId: $validated[
                            'customer_business_party_id'
                        ] ?? null,
                    customerName: $validated['customer_name'] ?? null,
                    customerDocument: $validated['customer_document'] ?? null,
                    notes: $validated['notes'] ?? null,
                    soldAt: $soldAt,
                    customerCreditOverrideReason:
                        $validated[
                            'customer_credit_override_reason'
                        ] ?? null,
                    receivableInstallmentCount:
                        filled(
                            $validated[
                                'receivable_installment_count'
                            ] ?? null
                        )
                            ? (int) $validated[
                                'receivable_installment_count'
                            ]
                            : null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            $message = $exception->getMessage();

            if (str_starts_with($message, 'Saldo insuficiente.')) {
                $message = $salePolicy->stockShortageMessage(
                    $validatedProductLines,
                    $request->user()
                ) ?? 'El stock cambió mientras se confirmaba la venta. Revisá la disponibilidad e intentá nuevamente.';
            }

            return back()
                ->withInput()
                ->withErrors([
                    'commerce' => $message,
                ]);
        }

        $sale->loadMissing('receivable');

        $message = $sale->receivable
            ? "Venta #{$sale->sale_number} confirmada con saldo pendiente registrado en cuenta corriente."
            : "Venta #{$sale->sale_number} confirmada con cobro exacto.";

        return redirect()
            ->route('commerce-sales.show', $sale)
            ->with('success', $message);
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
            'payments.financialAccount',
            'receivable.customer',
            'receivable.recognizedBy',
            'receivable.creditPolicy',
            'receivable.creditOverride.approvedBy',
            'postSaleRequests.requestedBy',
            'inventoryMovement.lines.product',
            'inventoryMovement.lines.sourceLocation',
        ]);

        return view('commerce-sales.show', [
            'sale' => $commerceSale,
        ]);
    }

    private function requiredMoneyMinor(
        ?AuthoritativeNumericInput $input
    ): int {
        if ($input === null) {
            throw new DomainException(
                'El importe monetario validado no está disponible.'
            );
        }

        return ExactDecimalLegacyAdapter::toMinorUnit(
            $input->canonical,
            2,
        );
    }

    private function optionalMoneyMinor(
        ?AuthoritativeNumericInput $input
    ): ?int {
        return $input === null
            ? null
            : ExactDecimalLegacyAdapter::toMinorUnit(
                $input->canonical,
                2,
            );
    }
}
