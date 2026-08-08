<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryMovementConfirmer;
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
use App\Models\CatalogProduct;
use App\Models\CommercePayment;
use App\Models\CommerceSale;
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
        private readonly OrganizationProductPriceReader $prices
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
                    'inventoryMovement.lines',
                ]);
            }

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

            if ($total <= 0 || $paymentTotal !== $total) {
                throw new DomainException(
                    'Los pagos deben cancelar exactamente el total de la venta.'
                );
            }

            [$customer, $customerName, $customerDocument] =
                $this->customerSnapshot(
                    $organizationId,
                    $data,
                    $service
                );
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

                CommercePayment::query()->create([
                    'organization_id' => $organizationId,
                    'commerce_sale_id' => $sale->id,
                    'position' => $index + 1,
                    'method' => $payment['method'],
                    'amount_minor' => $payment['amount_minor'],
                    'reference' => $payment['reference'],
                    'notes' => $payment['notes'],
                    'received_by_user_id' => $actor->id,
                    'paid_at' => $paidAt,
                ]);
            }

            $sale->status = CommerceSaleStatus::Confirmed;
            $sale->confirmed_at = CarbonImmutable::now();
            $sale->save();

            return $sale->refresh()->load([
                'lines.quoteLine',
                'lines.product',
                'payments',
                'inventoryMovement.lines',
            ]);
        }, 3);
    }

    /**
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

        if ($data->payments === []) {
            throw new DomainException(
                'La venta requiere al menos un medio de pago.'
            );
        }

        $payments = [];

        foreach ($data->payments as $payment) {
            if (! $payment instanceof CommercePaymentData) {
                throw new DomainException(
                    'Los pagos de la venta no son válidos.'
                );
            }

            $reference = $this->optional($payment->reference);

            if ($payment->amountMinor <= 0) {
                throw new DomainException(
                    'Cada pago debe poseer un importe positivo.'
                );
            }

            if ($payment->method->requiresReference() && $reference === null) {
                throw new DomainException(
                    'El medio de pago electrónico requiere una referencia.'
                );
            }

            $payments[] = [
                'method' => $payment->method->value,
                'amount_minor' => $payment->amountMinor,
                'reference' => $reference,
                'notes' => $this->optional($payment->notes),
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
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
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
