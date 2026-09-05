<?php

namespace App\Domain\Commerce;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommerceSaleLineType;
use App\Enums\CommerceSaleStatus;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Models\BusinessParty;
use App\Models\CashRegisterSession;
use App\Models\CatalogProduct;
use App\Models\CommercePayment;
use App\Models\CommerceSale;
use App\Models\FinancialAccount;
use App\Models\CommerceSaleLine;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class CommerceCheckoutManager
{
    public function __construct(
        private readonly InventoryMovementCreator $movementCreator,
        private readonly InventoryMovementConfirmer $movementConfirmer,
        private readonly CommercialAvailabilityReader $commercialAvailability,
        private readonly OrganizationProductPriceReader $prices,
        private readonly CashRegisterSessionManager $cashSessions,
        private readonly CashLedgerRecorder $cashLedger,
        private readonly CustomerCreditConsumer $creditConsumer,
        private readonly CustomerCreditPolicyGuard $creditPolicyGuard,
        private readonly CustomerCreditOverrideRecorder $creditOverrideRecorder,
        private readonly CustomerReceivableInstallmentScheduler $installmentScheduler,
        private readonly CustomerReceivableRecorder $receivableRecorder,
        private readonly CommerceSettlementComponentAnalyzer $settlementComponentAnalyzer
    ) {
    }

    public function checkout(
        CommerceCheckoutData $data,
        User $actor
    ): CommerceSale {
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): CommerceSale {
            $organizationId = $this->organizationId($actor);
            $this->lockOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $existing = CommerceSale::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint']
                );

                return $existing->load([
                    'lines.quoteLine',
                    'lines.product',
                    'payments',
                    'receivable',
                    'inventoryMovement.lines',
                ]);
            }

            $this->guardCommercialProductAvailability(
                $normalized['product_lines'],
                $actor
            );

            $cashSession = $this->guardOperationalCashPayments(
                $normalized['payments'],
                $actor,
                $organizationId,
                $normalized['currency_code']
            );

            $this->guardFinancialAccounts(
                $normalized['payments'],
                $organizationId,
                $normalized['currency_code']
            );

            $soldAt = $data->soldAt
                ? CarbonImmutable::instance($data->soldAt)
                : CarbonImmutable::now();
            $service = $this->serviceEvidence(
                $organizationId,
                $data->serviceOrderId,
                $normalized['currency_code']
            );
            $products = $this->productEvidence(
                $normalized['product_lines'],
                $organizationId,
                $normalized['currency_code'],
                $soldAt
            );

            if ($service === null && $products['lines'] === []) {
                throw new DomainException(
                    'La venta requiere un servicio aprobado o al menos un producto.'
                );
            }

            $serviceSubtotal = $service['subtotal_minor'] ?? 0;
            $productSubtotal = $products['subtotal_minor'];
            $total = $this->sumMoney(
                $serviceSubtotal,
                $productSubtotal,
                'El total de la venta supera el importe admitido.'
            );
            $paymentTotal = array_sum(array_column(
                $normalized['payments'],
                'amount_minor'
            ));
            $receivableAmount =
                $normalized['receivable_amount_minor'] ?? 0;
            $settledTotal = $this->sumMoney(
                $paymentTotal,
                $receivableAmount,
                'La liquidación de la venta supera el importe admitido.'
            );

            if ($total <= 0 || $settledTotal !== $total) {
                if (
                    $total > 0
                    && $settledTotal > 0
                    && $settledTotal !== $total
                ) {
                    $runtimeEvidence =
                        CommerceSettlementDiscrepancyException::
                            fromCheckoutData(
                                data: $data,
                                systemTotalMinor: $total,
                                settledTotalMinor: $settledTotal,
                                analyzer:
                                    $this->settlementComponentAnalyzer,
                            );

                    if (
                        $data->settlementDiscrepancyDecisionInput
                            !== null
                    ) {
                        throw CommerceSettlementDiscrepancyDecisionException::
                            fromInput(
                                runtimeEvidence: $runtimeEvidence,
                                input:
                                    $data
                                        ->settlementDiscrepancyDecisionInput,
                            );
                    }

                    throw $runtimeEvidence;
                }

                throw new DomainException(
                    'Los pagos y el saldo pendiente deben cubrir exactamente el total de la venta.'
                );
            }

            [$customer, $customerName, $customerDocument] =
                $this->customerSnapshot(
                    $organizationId,
                    $data,
                    $service
                );

            $creditDecision = null;

            if ($receivableAmount > 0) {
                if (! $customer) {
                    throw new DomainException(
                        'Una venta con saldo pendiente requiere un cliente vinculado.'
                    );
                }

                $creditDecision =
                    $this->creditPolicyGuard->decide(
                        $customer,
                        $normalized['currency_code'],
                        $receivableAmount,
                        $normalized[
                            'customer_credit_override_reason'
                        ],
                        $soldAt,
                        $actor
                    );
            }

            $publicId = (string) Str::uuid();
            $movement = $products['lines'] === []
                ? null
                : $this->createProductIssue(
                    $products['lines'],
                    $publicId,
                    $normalized['idempotency_key'],
                    $soldAt,
                    $actor
                );
            $saleNumber = ((int) CommerceSale::query()
                ->forOrganization($organizationId)
                ->max('sale_number')) + 1;
            $sale = CommerceSale::query()->create([
                'organization_id' => $organizationId,
                'public_id' => $publicId,
                'sale_number' => $saleNumber,
                'status' => CommerceSaleStatus::Building,
                'service_order_id' => $service === null
                    ? null
                    : $service['order']->id,
                'service_delivery_id' => $service === null
                    ? null
                    : $service['delivery_id'],
                'service_quote_decision_id' => $service === null
                    ? null
                    : $service['decision_id'],
                'service_quote_option_id' => $service === null
                    ? null
                    : $service['option']->id,
                'customer_business_party_id' => $customer?->id,
                'customer_name_snapshot' => $customerName,
                'customer_document_snapshot' => $customerDocument,
                'currency_code' => $normalized['currency_code'],
                'service_subtotal_minor' => $serviceSubtotal,
                'product_subtotal_minor' => $productSubtotal,
                'total_minor' => $total,
                'inventory_movement_id' => $movement?->id,
                'notes' => $normalized['notes'],
                'recorded_by_user_id' => $actor->id,
                'sold_at' => $soldAt,
                'confirmed_at' => null,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $position = 1;

            foreach ($service['lines'] ?? [] as $line) {
                CommerceSaleLine::query()->create([
                    'organization_id' => $organizationId,
                    'commerce_sale_id' => $sale->id,
                    'position' => $position++,
                    'line_type' => CommerceSaleLineType::Service,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                    'line_total_minor' => $line->line_total_minor,
                    'service_quote_line_id' => $line->id,
                    'catalog_product_id' => null,
                    'organization_product_price_id' => null,
                    'inventory_movement_line_id' => null,
                ]);
            }

            $movementLines = $movement?->lines->values();

            foreach ($products['lines'] as $index => $line) {
                CommerceSaleLine::query()->create([
                    'organization_id' => $organizationId,
                    'commerce_sale_id' => $sale->id,
                    'position' => $position++,
                    'line_type' => CommerceSaleLineType::Product,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                    'service_quote_line_id' => null,
                    'catalog_product_id' => $line['catalog_product_id'],
                    'organization_product_price_id' =>
                        $line['organization_product_price_id'],
                    'inventory_movement_line_id' =>
                        $movementLines?->get($index)?->id,
                ]);
            }

            foreach ($normalized['payments'] as $index => $payment) {
                $paidAt = $payment['paid_at'] === null
                    ? $soldAt
                    : CarbonImmutable::parse($payment['paid_at']);

                if ($paidAt->isAfter($soldAt)) {
                    throw new DomainException(
                        'Un pago no puede fecharse después de la venta que cancela.'
                    );
                }

                $paymentReference =
                    $payment['reference'];
                $financialAccountId =
                    $payment['financial_account_id'];

                if (
                    $payment['method']
                        === CommercePaymentMethod::AccountCredit->value
                ) {
                    if (
                        $sale->customer_business_party_id
                            === null
                    ) {
                        throw new DomainException(
                            'El crédito en cuenta requiere un cliente vinculado.'
                        );
                    }

                    $consumption =
                        $this->creditConsumer
                            ->consumeForSalePayment(
                                $sale,
                                $index + 1,
                                $payment[
                                    'amount_minor'
                                ],
                                $normalized[
                                    'idempotency_key'
                                ]
                                .':account-credit:'
                                .($index + 1),
                                $actor
                            );

                    $paymentReference =
                        $consumption->public_id;
                    $financialAccountId =
                        null;
                }

                $recordedPayment = CommercePayment::query()->create([
                    'organization_id' => $organizationId,
                    'commerce_sale_id' => $sale->id,
                    'financial_account_id' =>
                        $financialAccountId,
                    'position' => $index + 1,
                    'method' => $payment['method'],
                    'amount_minor' => $payment['amount_minor'],
                    'tendered_amount_minor' =>
                        $payment['tendered_amount_minor'],
                    'change_amount_minor' =>
                        $payment['change_amount_minor'],
                    'reference' => $paymentReference,
                    'card_brand' => $payment['card_brand'],
                    'card_network' => $payment['card_network'],
                    'card_last4' => $payment['card_last4'],
                    'installments' => $payment['installments'],
                    'processor' => $payment['processor'],
                    'external_operation_id' =>
                        $payment['external_operation_id'],
                    'authorization_code' =>
                        $payment['authorization_code'],
                    'provider_status' => $payment['provider_status'],
                    'notes' => $payment['notes'],
                    'received_by_user_id' => $actor->id,
                    'paid_at' => $paidAt,
                ]);

                if (
                    $payment['method']
                        === CommercePaymentMethod::Cash->value
                ) {
                    if (! $cashSession) {
                        throw new DomainException(
                            'El cobro en efectivo perdió su turno de caja.'
                        );
                    }

                    $this->cashLedger->recordSalePayment(
                        $cashSession,
                        $recordedPayment,
                        $actor
                    );
                }
            }

            if ($receivableAmount > 0) {
                if (! $creditDecision) {
                    throw new DomainException(
                        'La venta a crédito perdió su decisión de política.'
                    );
                }

                $creditOverride = null;

                if (
                    $creditDecision
                        ->requiresOverrideRecord()
                ) {
                    $creditOverride =
                        $this->creditOverrideRecorder
                            ->recordForSale(
                                $sale,
                                $creditDecision,
                                $actor
                            );
                }

                $receivable =
                    $this->receivableRecorder
                        ->recordForSale(
                            $sale,
                            $receivableAmount,
                            $normalized[
                                'receivable_due_on'
                            ],
                            $creditDecision,
                            $creditOverride,
                            $actor
                        );

                if (
                    $normalized[
                        'receivable_installment_count'
                    ] > 1
                ) {
                    $this->installmentScheduler
                        ->schedule(
                            $receivable,
                            $normalized[
                                'receivable_installment_count'
                            ],
                            $actor
                        );
                }
            }

            $sale->status = CommerceSaleStatus::Confirmed;
            $sale->confirmed_at = CarbonImmutable::now();
            $sale->save();

            return $sale->refresh()->load([
                'lines.quoteLine',
                'lines.product',
                'payments',
                'receivable',
                'inventoryMovement.lines',
            ]);
        }, 3);
    }

    /**
     * Reservation Foundation V1 blocks ordinary checkout from consuming
     * quantity promised by any effective reservation. Own-reservation
     * consumption remains a future bounded cut.
     * @param list<array<string, mixed>> $lines
     */
    private function guardCommercialProductAvailability(array $lines, User $actor): void
    {
        if ($lines === []) { return; }
        $positions = $this->commercialAvailability->positions($actor);
        $requested = [];
        foreach ($lines as $line) {
            $condition = $line['condition'] instanceof \App\Enums\InventoryCondition
                ? $line['condition']->value : (string) $line['condition'];
            $key = implode(':', [$line['catalog_product_id'],$line['source_location_id'],$condition]);
            $quantity = InventoryQuantity::positive((string) $line['quantity']);
            $requested[$key] = isset($requested[$key])
                ? InventoryQuantity::add($requested[$key], $quantity) : $quantity;
        }
        foreach ($requested as $key => $quantity) {
            [$productId,$locationId,$condition] = explode(':', $key, 3);
            $position = $positions->first(fn (CommercialAvailabilityPosition $position): bool =>
                $position->catalogProductId === (int) $productId
                && $position->inventoryLocationId === (int) $locationId
                && $position->condition->value === $condition
            );
            $available = $position?->commercialAvailableQuantity ?? InventoryQuantity::signed('0');
            if (InventoryQuantity::isNegative(InventoryQuantity::subtract($available, $quantity))) {
                throw new DomainException('La venta supera la disponibilidad comercial no reservada.');
            }
        }
    }    /**
     * @return array{
     *     order: ServiceOrder,
     *     delivery_id: int,
     *     decision_id: int,
     *     option: \App\Models\ServiceQuoteOption,
     *     lines: \Illuminate\Support\Collection<int, \App\Models\ServiceQuoteLine>,
     *     subtotal_minor: int
     * }|null
     */
    private function serviceEvidence(
        int $organizationId,
        ?int $serviceOrderId,
        string $currencyCode
    ): ?array {
        if ($serviceOrderId === null) {
            return null;
        }

        $order = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->whereKey($serviceOrderId)
            ->with('delivery')
            ->lockForUpdate()
            ->first();

        if (! $order || $order->status !== ServiceOrderStatus::Delivered) {
            throw new DomainException(
                'Sólo una orden entregada puede incorporarse al cobro final.'
            );
        }

        if (! $order->delivery) {
            throw new DomainException(
                'La orden entregada carece de evidencia física de entrega.'
            );
        }

        $quote = ServiceQuote::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $order->id)
            ->with('decision.selectedOption.lines')
            ->latest('revision')
            ->lockForUpdate()
            ->first();
        $decision = $quote?->decision;
        $option = $decision?->selectedOption;

        if (
            ! $quote
            || ! $decision
            || $decision->decision !== ServiceQuoteDecisionType::Approved
            || ! $option
        ) {
            throw new DomainException(
                'La orden no posee una última alternativa aprobada liquidable.'
            );
        }

        if ($quote->currency_code !== $currencyCode) {
            throw new DomainException(
                'La moneda de la venta no coincide con el presupuesto aprobado.'
            );
        }

        if ($option->lines->isEmpty()) {
            throw new DomainException(
                'La alternativa aprobada no posee conceptos liquidables.'
            );
        }

        return [
            'order' => $order,
            'delivery_id' => (int) $order->delivery->id,
            'decision_id' => (int) $decision->id,
            'option' => $option,
            'lines' => $option->lines,
            'subtotal_minor' => (int) $option->total_minor,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function productEvidence(
        array $lines,
        int $organizationId,
        string $currencyCode,
        CarbonImmutable $soldAt
    ): array {
        if ($lines === []) {
            return ['lines' => [], 'subtotal_minor' => 0];
        }

        $products = CatalogProduct::query()
            ->where('active', true)
            ->whereIn('id', array_column($lines, 'catalog_product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $normalized = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $product = $products->get($line['catalog_product_id']);

            if (! $product) {
                throw new DomainException(
                    'Un producto de la venta no existe o se encuentra inactivo.'
                );
            }

            $organizationPrice = null;

            if ($line['unit_price_minor'] === null) {
                $organizationPrice = $this->prices->priceAt(
                    $organizationId,
                    (int) $line['catalog_product_id'],
                    $currencyCode,
                    $soldAt
                );
                $line['unit_price_minor'] =
                    (int) $organizationPrice->amount_minor;
            }

            $line['organization_product_price_id'] =
                $organizationPrice?->id;
            $line['description'] = $product->name;
            $line['unit_code'] = $product->base_unit_code;
            $line['line_total_minor'] = $this->lineTotalMinor(
                $line['quantity'],
                $line['unit_price_minor']
            );
            $subtotal = $this->sumMoney(
                $subtotal,
                $line['line_total_minor'],
                'El subtotal de productos supera el importe admitido.'
            );
            $normalized[] = $line;
        }

        return ['lines' => $normalized, 'subtotal_minor' => $subtotal];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function createProductIssue(
        array $lines,
        string $publicId,
        string $idempotencyKey,
        CarbonImmutable $soldAt,
        User $actor
    ): \App\Models\InventoryMovement {
        $movement = $this->movementCreator->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Issue,
                effectiveAt: $soldAt,
                reason: 'Salida por venta comercial '.$publicId.'.',
                idempotencyKey: $idempotencyKey.':inventory',
                lines: array_map(
                    fn (array $line): InventoryMovementLineData =>
                        new InventoryMovementLineData(
                            catalogProductId: $line['catalog_product_id'],
                            condition: $line['condition'],
                            enteredQuantity: $line['quantity'],
                            enteredUnitCode: $line['unit_code'],
                            sourceLocationId: $line['source_location_id'],
                            notes: 'Producto vendido en operación '.$publicId.'.'
                        ),
                    $lines
                ),
                sourceType: 'commerce_sale',
                sourceId: $publicId,
                sourceReference: 'Venta comercial '.$publicId,
                metadata: ['commerce_sale_public_id' => $publicId]
            ),
            $actor
        );

        return $this->movementConfirmer->confirm($movement, $actor);
    }

    /** @return array{?BusinessParty, string, ?string} */
    private function customerSnapshot(
        int $organizationId,
        CommerceCheckoutData $data,
        ?array $service
    ): array {
        $serviceCustomerId = $service === null
            ? null
            : $service['order']->customer_business_party_id;

        if (
            $serviceCustomerId !== null
            && $data->customerBusinessPartyId !== null
            && $data->customerBusinessPartyId !== $serviceCustomerId
        ) {
            throw new DomainException(
                'El cliente de la venta no coincide con la orden entregada.'
            );
        }

        $customerId = $serviceCustomerId
            ?? $data->customerBusinessPartyId;
        $customer = $customerId === null
            ? null
            : BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKey($customerId)
                ->lockForUpdate()
                ->first();

        if ($customerId !== null && ! $customer) {
            throw new DomainException(
                'El cliente no pertenece a la organización activa.'
            );
        }

        if (
            $service === null
            && $customer !== null
            && ServiceOrder::query()
                ->forOrganization($organizationId)
                ->where('customer_business_party_id', $customer->id)
                ->unsettledDelivered()
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'El cliente posee una reparación entregada pendiente de liquidación; debe incorporarse a la venta.'
            );
        }

        $delivery = $service === null
            ? null
            : $service['order']->delivery;
        $name = $this->optional($data->customerName)
            ?? $customer?->name
            ?? $delivery?->recipient_name
            ?? 'Consumidor final';
        $document = $this->optional($data->customerDocument)
            ?? $customer?->tax_id
            ?? $delivery?->recipient_document;

        return [$customer, $name, $document];
    }

    /** @return array<string, mixed> */
    private function normalize(CommerceCheckoutData $data): array
    {
        $currency = strtoupper(trim($data->currencyCode));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException(
                'La moneda debe expresarse con un código ISO de tres letras.'
            );
        }

        $productLines = [];

        foreach ($data->productLines as $line) {
            if (! $line instanceof CommerceProductLineData) {
                throw new DomainException(
                    'Las líneas de productos de la venta no son válidas.'
                );
            }

            if (
                $line->catalogProductId <= 0
                || $line->sourceLocationId <= 0
                || (
                    $line->unitPriceMinor !== null
                    && $line->unitPriceMinor < 0
                )
            ) {
                throw new DomainException(
                    'Una línea de producto contiene datos inválidos.'
                );
            }

            $productLines[] = [
                'catalog_product_id' => $line->catalogProductId,
                'source_location_id' => $line->sourceLocationId,
                'condition' => $line->condition,
                'quantity' => $this->quantity($line->quantity),
                'unit_price_minor' => $line->unitPriceMinor,
                'description' => $this->optional($line->description),
            ];
        }

        $receivableAmount = $data->receivableAmountMinor;

        if (
            $receivableAmount !== null
            && $receivableAmount <= 0
        ) {
            throw new DomainException(
                'El saldo pendiente debe poseer un importe positivo.'
            );
        }

        $receivableDueOn = $data->receivableDueOn === null
            ? null
            : CarbonImmutable::instance(
                $data->receivableDueOn
            )->toDateString();

        if (
            $receivableAmount === null
            && $receivableDueOn !== null
        ) {
            throw new DomainException(
                'No puede informarse vencimiento sin saldo pendiente.'
            );
        }

        $receivableInstallmentCount =
            $data->receivableInstallmentCount;

        if ($receivableAmount === null) {
            if ($receivableInstallmentCount !== null) {
                throw new DomainException(
                    'No pueden informarse cuotas propias sin saldo pendiente.'
                );
            }
        } else {
            $receivableInstallmentCount ??= 1;

            if (
                $receivableInstallmentCount < 1
                || $receivableInstallmentCount > 120
            ) {
                throw new DomainException(
                    'La cantidad de cuotas propias debe estar entre 1 y 120.'
                );
            }

            if (
                $receivableInstallmentCount > 1
                && $receivableDueOn === null
            ) {
                throw new DomainException(
                    'Las cuotas propias requieren un primer vencimiento.'
                );
            }

            if (
                $receivableInstallmentCount > 1
                && $receivableAmount
                    < $receivableInstallmentCount
            ) {
                throw new DomainException(
                    'El importe pendiente es demasiado pequeño para repartirlo en esa cantidad de cuotas.'
                );
            }
        }

        if (
            $data->payments === []
            && $receivableAmount === null
        ) {
            throw new DomainException(
                'La venta requiere un pago o un saldo pendiente autorizado.'
            );
        }

        $creditOverrideReason = $this->paymentText(
            $data->customerCreditOverrideReason,
            2000,
            'El motivo de excepción de crédito'
        );

        if (
            $receivableAmount === null
            && $creditOverrideReason !== null
        ) {
            throw new DomainException(
                'No puede informarse una excepción de crédito sin saldo pendiente.'
            );
        }

        $payments = [];

        foreach ($data->payments as $payment) {
            if (! $payment instanceof CommercePaymentData) {
                throw new DomainException(
                    'Los pagos de la venta no son válidos.'
                );
            }

            $financialAccountId = $payment->financialAccountId;

            if (
                $financialAccountId !== null
                && $financialAccountId <= 0
            ) {
                throw new DomainException(
                    'La cuenta destino del pago no es válida.'
                );
            }

            $tenderedAmountMinor = $payment->tenderedAmountMinor;
            $changeAmountMinor = null;

            if ($payment->method === CommercePaymentMethod::Cash) {
                if ($tenderedAmountMinor !== null) {
                    if ($tenderedAmountMinor < $payment->amountMinor) {
                        throw new DomainException(
                            'El dinero entregado no puede ser menor que el importe aplicado.'
                        );
                    }

                    $changeAmountMinor =
                        $tenderedAmountMinor - $payment->amountMinor;
                }
            } elseif ($tenderedAmountMinor !== null) {
                throw new DomainException(
                    'Sólo el efectivo admite dinero entregado y vuelto.'
                );
            }

            $reference = $this->paymentText(
                $payment->reference,
                255,
                'La referencia del pago'
            );
            $notes = $this->paymentText(
                $payment->notes,
                2000,
                'Las notas del pago'
            );
            $cardBrand = $this->paymentText(
                $payment->cardBrand,
                50,
                'La marca de tarjeta'
            );
            $cardNetwork = $this->paymentText(
                $payment->cardNetwork,
                50,
                'La red de tarjeta'
            );
            $cardLast4 = $this->paymentText(
                $payment->cardLast4,
                4,
                'Los últimos 4 de tarjeta'
            );
            $processor = $this->paymentText(
                $payment->processor,
                100,
                'El procesador del pago'
            );
            $externalOperationId = $this->paymentText(
                $payment->externalOperationId,
                191,
                'La operación externa'
            );
            $authorizationCode = $this->paymentText(
                $payment->authorizationCode,
                100,
                'El código de autorización'
            );
            $providerStatus = $this->paymentText(
                $payment->providerStatus,
                50,
                'El estado informado por el proveedor'
            );

            if ($payment->amountMinor <= 0) {
                throw new DomainException(
                    'Cada pago debe poseer un importe positivo.'
                );
            }

            if (
                $payment->method
                    === CommercePaymentMethod::AccountCredit
            ) {
                if ($financialAccountId !== null) {
                    throw new DomainException(
                        'El crédito en cuenta no utiliza una cuenta financiera destino.'
                    );
                }

                if ($reference !== null) {
                    throw new DomainException(
                        'La referencia del crédito en cuenta es generada por SRCM.'
                    );
                }

                if (
                    $tenderedAmountMinor !== null
                    || $cardBrand !== null
                    || $cardNetwork !== null
                    || $cardLast4 !== null
                    || $payment->installments !== null
                    || $processor !== null
                    || $externalOperationId !== null
                    || $authorizationCode !== null
                    || $providerStatus !== null
                ) {
                    throw new DomainException(
                        'El crédito en cuenta no admite efectivo, tarjeta ni evidencia de proveedor.'
                    );
                }
            }

            if ($payment->method->requiresReference() && $reference === null) {
                throw new DomainException(
                    'El medio de pago electrónico requiere una referencia.'
                );
            }

            if (
                $cardLast4 !== null
                && preg_match('/^\d{4}$/D', $cardLast4) !== 1
            ) {
                throw new DomainException(
                    'Los últimos 4 de tarjeta deben contener exactamente cuatro dígitos.'
                );
            }

            if (
                $payment->installments !== null
                && (
                    $payment->installments < 1
                    || $payment->installments > 120
                )
            ) {
                throw new DomainException(
                    'La cantidad de cuotas debe estar entre 1 y 120.'
                );
            }

            $isCard = in_array(
                $payment->method,
                [
                    CommercePaymentMethod::DebitCard,
                    CommercePaymentMethod::CreditCard,
                ],
                true
            );

            if (
                ! $isCard
                && (
                    $cardBrand !== null
                    || $cardNetwork !== null
                    || $cardLast4 !== null
                    || $payment->installments !== null
                )
            ) {
                throw new DomainException(
                    'Los datos de tarjeta sólo pueden asociarse a un pago con tarjeta.'
                );
            }

            if (
                $payment->method === CommercePaymentMethod::Cash
                && (
                    $processor !== null
                    || $externalOperationId !== null
                    || $authorizationCode !== null
                    || $providerStatus !== null
                )
            ) {
                throw new DomainException(
                    'El efectivo no admite evidencia de procesador u operación externa.'
                );
            }

            $payments[] = [
                'financial_account_id' => $financialAccountId,
                'method' => $payment->method->value,
                'amount_minor' => $payment->amountMinor,
                'tendered_amount_minor' => $tenderedAmountMinor,
                'change_amount_minor' => $changeAmountMinor,
                'reference' => $reference,
                'card_brand' => $cardBrand,
                'card_network' => $cardNetwork,
                'card_last4' => $cardLast4,
                'installments' => $payment->installments,
                'processor' => $processor,
                'external_operation_id' => $externalOperationId,
                'authorization_code' => $authorizationCode,
                'provider_status' => $providerStatus,
                'notes' => $notes,
                'paid_at' => $payment->paidAt
                    ? CarbonImmutable::instance($payment->paidAt)
                        ->toIso8601String()
                    : null,
            ];
        }

        $normalized = [
            'currency_code' => $currency,
            'service_order_id' => $data->serviceOrderId,
            'customer_business_party_id' =>
                $data->customerBusinessPartyId,
            'customer_name' => $this->optional($data->customerName),
            'customer_document' =>
                $this->optional($data->customerDocument),
            'notes' => $this->optional($data->notes),
            'sold_at' => $data->soldAt
                ? CarbonImmutable::instance($data->soldAt)->toIso8601String()
                : null,
            'product_lines' => $productLines,
            'payments' => $payments,
            'receivable_amount_minor' => $receivableAmount,
            'receivable_due_on' => $receivableDueOn,
            'receivable_installment_count' =>
                $receivableInstallmentCount,
            'customer_credit_override_reason' =>
                $creditOverrideReason,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    private function paymentText(
        ?string $value,
        int $maxLength,
        string $label
    ): ?string {
        $value = $this->optional($value);

        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new DomainException(
                "{$label} supera la longitud admitida."
            );
        }

        return $value;
    }

    private function quantity(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new DomainException(
                'La cantidad debe expresarse como decimal positivo con punto.'
            );
        }

        try {
            $quantity = BigDecimal::of($value)->toScale(
                6,
                RoundingMode::Unnecessary
            );

            if (! $quantity->isPositive()) {
                throw new DomainException(
                    'La cantidad vendida debe ser mayor que cero.'
                );
            }

            return (string) $quantity;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad vendida supera la precisión admitida.',
                previous: $exception
            );
        }
    }

    private function lineTotalMinor(string $quantity, int $unitPrice): int
    {
        try {
            $total = BigDecimal::of($quantity)
                ->multipliedBy($unitPrice)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toBigInteger();

            if ($total->isGreaterThan(BigInteger::of(PHP_INT_MAX))) {
                throw new DomainException(
                    'El total de la línea supera el importe admitido.'
                );
            }

            return (int) (string) $total;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad y el precio producen una fracción de centavo.',
                previous: $exception
            );
        }
    }

    private function sumMoney(int $left, int $right, string $message): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new DomainException($message);
        }

        return $left + $right;
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function guardOperationalCashPayments(
        array $payments,
        User $actor,
        int $organizationId,
        string $currencyCode
    ): ?CashRegisterSession {
        $cashPayments = collect($payments)
            ->filter(
                fn (array $payment): bool =>
                    $payment['method']
                        === CommercePaymentMethod::Cash->value
            )
            ->values();

        if ($cashPayments->isEmpty()) {
            return null;
        }

        $session = $this->cashSessions->lockCurrentFor($actor);

        if (! $session) {
            throw new DomainException(
                'Para cobrar en efectivo, abrí un turno de caja.'
            );
        }

        $register = $session->register;
        $account = $register?->financialAccount;

        if (
            (int) $session->organization_id !== $organizationId
            || $session->currency_code !== $currencyCode
            || ! $register
            || ! $register->active
            || (int) $register->organization_id !== $organizationId
            || ! $account
            || ! $account->active
            || (int) $account->organization_id !== $organizationId
            || $account->currency_code !== $currencyCode
        ) {
            throw new DomainException(
                'El turno de caja no está disponible para la moneda de la venta.'
            );
        }

        foreach ($cashPayments as $payment) {
            if (
                (int) ($payment['financial_account_id'] ?? 0)
                    !== (int) $account->id
            ) {
                throw new DomainException(
                    'El destino del efectivo debe ser la cuenta de la caja del turno abierto.'
                );
            }
        }

        return $session;
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function guardFinancialAccounts(
        array $payments,
        int $organizationId,
        string $currencyCode
    ): void {
        $ids = collect($payments)
            ->pluck('financial_account_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $accounts = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $ids->all())
            ->where('active', true)
            ->where('currency_code', $currencyCode)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== $ids->count()) {
            throw new DomainException(
                'Una cuenta destino no está activa, no pertenece a la organización o usa otra moneda.'
            );
        }
    }

    private function organizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        return $organizationId;
    }

    private function lockOrganization(int $organizationId): void
    {
        if (! DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->exists()) {
            throw new DomainException('La organización no está activa.');
        }
    }

    private function guardActor(int $organizationId, User $actor): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership?->role->canRecordCommerceSale()) {
            throw new DomainException(
                'El usuario no puede confirmar operaciones comerciales.'
            );
        }
    }

    private function guardReceivableAuthority(
        int $organizationId,
        User $actor
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership?->role->canCreateCustomerReceivable()) {
            throw new DomainException(
                'El usuario no puede registrar una venta con saldo pendiente.'
            );
        }
    }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 90) {
            throw new DomainException(
                'La clave de idempotencia comercial es inválida.'
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo consolidar la operación comercial.',
                previous: $exception
            );
        }
    }

    private function guardFingerprint(string $stored, string $expected): void
    {
        if (! hash_equals($stored, $expected)) {
            throw new DomainException(
                'La clave de idempotencia comercial ya fue utilizada con otros datos.'
            );
        }
    }
}
