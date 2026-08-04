<?php

namespace App\Domain\Service;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServicePartSource;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWorkStatus;
use App\Models\CatalogProduct;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServicePartConsumption;
use App\Models\ServicePartPurchase;
use App\Models\ServicePartPurchaseLine;
use App\Models\ServicePartRequirement;
use App\Models\ServiceQuoteLine;
use App\Models\ServiceWarrantyClaimResolution;
use App\Models\ServiceWorkItem;
use App\Models\Supplier;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class ServicePartManager
{
    public function __construct(
        private readonly InventoryMovementCreator $movementCreator,
        private readonly InventoryMovementConfirmer $movementConfirmer
    ) {}

    public function plan(
        ServicePartRequirementData $data,
        User $actor
    ): ServicePartRequirement {
        $normalized = $this->normalizeRequirement($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServicePartRequirement {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'plan');

            $existing = ServicePartRequirement::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'necesidad de repuesto'
                );

                return $existing->load(['workItem', 'quoteLine', 'product']);
            }

            $workItem = $this->lockedWorkItem(
                $organizationId,
                $data->serviceWorkItemId
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $workItem->service_order_id
            );

            if (! in_array($order->status, [
                ServiceOrderStatus::InProgress,
                ServiceOrderStatus::AwaitingParts,
            ], true) || ! in_array($workItem->status, [
                ServiceWorkStatus::Planned,
                ServiceWorkStatus::InProgress,
            ], true)) {
                throw new DomainException(
                    'La orden o el trabajo no admiten planificación de repuestos.'
                );
            }

            $quoteLine = ServiceQuoteLine::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceQuoteLineId)
                ->lockForUpdate()
                ->first();
            $product = CatalogProduct::query()
                ->whereKey($data->catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $quoteLine
                || $quoteLine->line_type !== ServiceQuoteLineType::Part
                || (int) $quoteLine->service_quote_option_id
                    !== (int) $workItem->service_quote_option_id
            ) {
                throw new DomainException(
                    'El repuesto debe corresponder a una línea aprobada del trabajo.'
                );
            }

            if (! $product) {
                throw new DomainException(
                    'El producto usado como repuesto no existe o está inactivo.'
                );
            }

            if (ServicePartRequirement::query()
                ->forOrganization($organizationId)
                ->where('service_quote_line_id', $quoteLine->id)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(
                    'La línea aprobada ya posee una necesidad de repuesto.'
                );
            }

            if (! InventoryQuantity::equal(
                $normalized['required_quantity'],
                $quoteLine->quantity
            )) {
                throw new DomainException(
                    'La cantidad requerida debe coincidir con la cantidad aprobada.'
                );
            }

            InventoryQuantity::assertFitsScale(
                $normalized['required_quantity'],
                (int) $product->quantity_scale,
                'La cantidad requerida'
            );

            $plannedAt = CarbonImmutable::now();
            $requirement = ServicePartRequirement::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'service_work_item_id' => $workItem->id,
                'service_quote_line_id' => $quoteLine->id,
                'catalog_product_id' => $product->id,
                'condition' => $data->condition,
                'source' => $data->source,
                'required_quantity' => $normalized['required_quantity'],
                'base_unit_code' => $product->base_unit_code,
                'created_by_user_id' => $actor->id,
                'planned_at' => $plannedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            if (
                $data->source === ServicePartSource::DirectPurchase
                && $order->status === ServiceOrderStatus::InProgress
            ) {
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::AwaitingParts,
                    $actor,
                    'La orden espera un repuesto comprado específicamente.',
                    $plannedAt
                );
            }

            return $requirement->load(['workItem', 'quoteLine', 'product']);
        }, 3);
    }

    public function planWarranty(
        ServiceWarrantyPartRequirementData $data,
        User $actor
    ): ServicePartRequirement {
        $normalized = $this->normalizeWarrantyRequirement($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServicePartRequirement {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'plan');

            $existing = ServicePartRequirement::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'necesidad de repuesto de garantía'
                );

                return $existing->load([
                    'workItem',
                    'warrantyResolution.claim',
                    'product',
                ]);
            }

            $workItem = $this->lockedWorkItem(
                $organizationId,
                $data->serviceWorkItemId
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $workItem->service_order_id
            );

            if (! in_array($order->status, [
                ServiceOrderStatus::InProgress,
                ServiceOrderStatus::AwaitingParts,
            ], true) || ! in_array($workItem->status, [
                ServiceWorkStatus::Planned,
                ServiceWorkStatus::InProgress,
            ], true)) {
                throw new DomainException(
                    'La orden o el trabajo no admiten repuestos de garantía.'
                );
            }

            $resolution = ServiceWarrantyClaimResolution::query()
                ->forOrganization($organizationId)
                ->with('claim')
                ->whereKey($data->serviceWarrantyClaimResolutionId)
                ->lockForUpdate()
                ->first();
            $product = CatalogProduct::query()
                ->whereKey($data->catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $resolution
                || ! $resolution->outcome->authorizesCorrectiveWork()
                || ! $resolution->claim
                || $resolution->claim->status
                    !== ServiceWarrantyClaimStatus::InCorrectiveWork
                || (int) $resolution->claim->corrective_service_order_id
                    !== $order->id
                || (int) $workItem->service_warranty_claim_resolution_id
                    !== $resolution->id
                || $workItem->service_quote_option_id !== null
            ) {
                throw new DomainException(
                    'El repuesto no pertenece al alcance correctivo de garantía.'
                );
            }

            if (! $product) {
                throw new DomainException(
                    'El producto usado como repuesto no existe o está inactivo.'
                );
            }

            InventoryQuantity::assertFitsScale(
                $normalized['required_quantity'],
                (int) $product->quantity_scale,
                'La cantidad requerida'
            );

            $plannedAt = CarbonImmutable::now();
            $requirement = ServicePartRequirement::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'service_work_item_id' => $workItem->id,
                'service_quote_line_id' => null,
                'service_warranty_claim_resolution_id' => $resolution->id,
                'catalog_product_id' => $product->id,
                'condition' => $data->condition,
                'source' => $data->source,
                'required_quantity' => $normalized['required_quantity'],
                'base_unit_code' => $product->base_unit_code,
                'created_by_user_id' => $actor->id,
                'planned_at' => $plannedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            if (
                $data->source === ServicePartSource::DirectPurchase
                && $order->status === ServiceOrderStatus::InProgress
            ) {
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::AwaitingParts,
                    $actor,
                    'La garantía espera un repuesto comprado específicamente.',
                    $plannedAt
                );
            }

            return $requirement->load([
                'workItem',
                'warrantyResolution.claim',
                'product',
            ]);
        }, 3);
    }

    public function recordPurchase(
        ServicePartPurchaseData $data,
        User $actor
    ): ServicePartPurchase {
        $normalized = $this->normalizePurchase($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServicePartPurchase {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'purchase');

            $existing = ServicePartPurchase::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'compra afectada'
                );

                return $existing->load(['supplier.party', 'lines.requirement']);
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if (! in_array($order->status, [
                ServiceOrderStatus::AwaitingParts,
                ServiceOrderStatus::InProgress,
            ], true)) {
                throw new DomainException(
                    'La orden no admite registrar compras de repuestos.'
                );
            }

            $supplier = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($data->supplierId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw new DomainException(
                    'El proveedor no existe, no está activo o pertenece a otra organización.'
                );
            }

            $requirements = ServicePartRequirement::query()
                ->forOrganization($organizationId)
                ->with('product')
                ->whereIn(
                    'id',
                    collect($normalized['lines'])
                        ->pluck('service_part_requirement_id')
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($normalized['lines'] as $line) {
                $requirement = $requirements->get(
                    $line['service_part_requirement_id']
                );

                if (
                    ! $requirement
                    || (int) $requirement->service_order_id !== $order->id
                    || $requirement->source
                        !== ServicePartSource::DirectPurchase
                ) {
                    throw new DomainException(
                        'Cada línea debe imputarse a un repuesto de compra directa de esta orden.'
                    );
                }

                InventoryQuantity::assertFitsScale(
                    $line['quantity'],
                    (int) $requirement->product->quantity_scale,
                    'La cantidad comprada'
                );

                $alreadyPurchased = (string) ServicePartPurchaseLine::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'service_part_requirement_id',
                        $requirement->id
                    )
                    ->sum('quantity');
                $newTotal = InventoryQuantity::add(
                    $alreadyPurchased,
                    $line['quantity']
                );

                if (! InventoryQuantity::lessThanOrEqual(
                    $newTotal,
                    $requirement->required_quantity
                )) {
                    throw new DomainException(
                        'La compra supera la cantidad requerida por la orden.'
                    );
                }
            }

            $purchase = ServicePartPurchase::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'supplier_id' => $supplier->id,
                'currency_code' => $normalized['currency_code'],
                'parts_total_minor' => $normalized['parts_total_minor'],
                'logistics_cost_minor' => $normalized['logistics_cost_minor'],
                'grand_total_minor' => $normalized['grand_total_minor'],
                'document_reference' => $normalized['document_reference'],
                'notes' => $normalized['notes'],
                'purchased_by_user_id' => $actor->id,
                'purchased_at' => $normalized['purchased_at'],
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            foreach ($normalized['lines'] as $index => $line) {
                ServicePartPurchaseLine::query()->create([
                    'organization_id' => $organizationId,
                    'service_part_purchase_id' => $purchase->id,
                    'service_part_requirement_id' => $line['service_part_requirement_id'],
                    'sequence' => $index + 1,
                    'quantity' => $line['quantity'],
                    'unit_cost_minor' => $line['unit_cost_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                ]);
            }

            if (
                $order->status === ServiceOrderStatus::AwaitingParts
                && $this->allDirectRequirementsPurchased(
                    $organizationId,
                    $order
                )
            ) {
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::InProgress,
                    $actor,
                    'Todos los repuestos comprados para la orden fueron recibidos.',
                    CarbonImmutable::now()
                );
            }

            return $purchase->load(['supplier.party', 'lines.requirement']);
        }, 3);
    }

    public function consume(
        ServicePartConsumptionData $data,
        User $actor
    ): ServicePartConsumption {
        $normalized = $this->normalizeConsumption($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServicePartConsumption {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'consume');

            $existing = ServicePartConsumption::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'consumo de repuesto'
                );

                return $existing->load([
                    'requirement',
                    'purchaseLine',
                    'inventoryMovementLine.movement',
                ]);
            }

            $requirement = ServicePartRequirement::query()
                ->forOrganization($organizationId)
                ->with('product')
                ->whereKey($data->servicePartRequirementId)
                ->lockForUpdate()
                ->first();

            if (! $requirement) {
                throw new DomainException(
                    'La necesidad de repuesto no pertenece a la organización activa.'
                );
            }

            InventoryQuantity::assertFitsScale(
                $normalized['quantity'],
                (int) $requirement->product->quantity_scale,
                'La cantidad consumida'
            );

            $workItem = $this->lockedWorkItem(
                $organizationId,
                (int) $requirement->service_work_item_id
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $requirement->service_order_id
            );

            if (
                $workItem->status !== ServiceWorkStatus::InProgress
                || $order->status !== ServiceOrderStatus::InProgress
            ) {
                throw new DomainException(
                    'El repuesto sólo puede consumirse durante un trabajo en ejecución.'
                );
            }

            $alreadyConsumed = (string) ServicePartConsumption::query()
                ->forOrganization($organizationId)
                ->where('service_part_requirement_id', $requirement->id)
                ->sum('quantity');
            $newTotal = InventoryQuantity::add(
                $alreadyConsumed,
                $normalized['quantity']
            );

            if (! InventoryQuantity::lessThanOrEqual(
                $newTotal,
                $requirement->required_quantity
            )) {
                throw new DomainException(
                    'El consumo supera la cantidad requerida por el trabajo.'
                );
            }

            $purchaseLineId = null;
            $movementLineId = null;

            if ($requirement->source === ServicePartSource::Stock) {
                if (
                    $data->sourceLocationId === null
                    || $data->servicePartPurchaseLineId !== null
                ) {
                    throw new DomainException(
                        'El consumo desde stock requiere ubicación y no una compra directa.'
                    );
                }

                $movement = $this->movementCreator->create(
                    new InventoryMovementDraftData(
                        type: InventoryMovementType::Issue,
                        effectiveAt: CarbonImmutable::now(),
                        reason: 'Repuesto consumido en orden '.$order->order_number.'.',
                        idempotencyKey: $this->derivedKey(
                            $normalized['idempotency_key'],
                            'stock-issue'
                        ),
                        lines: [new InventoryMovementLineData(
                            catalogProductId: (int) $requirement->catalog_product_id,
                            condition: $requirement->condition,
                            enteredQuantity: $normalized['quantity'],
                            enteredUnitCode: $requirement->base_unit_code,
                            sourceLocationId: $data->sourceLocationId,
                            notes: 'Consumo trazado por reparación.'
                        )],
                        sourceType: 'service_order',
                        sourceId: (string) $order->id,
                        sourceReference: $order->order_number,
                        metadata: [
                            'service_part_requirement_id' => $requirement->id,
                            'service_work_item_id' => $workItem->id,
                        ]
                    ),
                    $actor
                );
                $confirmed = $this->movementConfirmer->confirm(
                    $movement,
                    $actor
                );
                $movementLineId = (int) $confirmed->lines->sole()->id;
            } else {
                if (
                    $data->sourceLocationId !== null
                    || $data->servicePartPurchaseLineId === null
                ) {
                    throw new DomainException(
                        'El consumo comprado para la orden requiere su línea de compra.'
                    );
                }

                $purchaseLine = ServicePartPurchaseLine::query()
                    ->forOrganization($organizationId)
                    ->whereKey($data->servicePartPurchaseLineId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $purchaseLine
                    || (int) $purchaseLine->service_part_requirement_id
                        !== $requirement->id
                ) {
                    throw new DomainException(
                        'La línea de compra no corresponde al repuesto requerido.'
                    );
                }

                $lineConsumed = (string) ServicePartConsumption::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'service_part_purchase_line_id',
                        $purchaseLine->id
                    )
                    ->sum('quantity');

                if (! InventoryQuantity::lessThanOrEqual(
                    InventoryQuantity::add(
                        $lineConsumed,
                        $normalized['quantity']
                    ),
                    $purchaseLine->quantity
                )) {
                    throw new DomainException(
                        'El consumo supera la cantidad disponible en la compra afectada.'
                    );
                }

                $purchaseLineId = $purchaseLine->id;
            }

            return ServicePartConsumption::query()->create([
                'organization_id' => $organizationId,
                'service_part_requirement_id' => $requirement->id,
                'service_part_purchase_line_id' => $purchaseLineId,
                'inventory_movement_line_id' => $movementLineId,
                'quantity' => $normalized['quantity'],
                'consumed_by_user_id' => $actor->id,
                'consumed_at' => CarbonImmutable::now(),
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ])->load([
                'requirement',
                'purchaseLine',
                'inventoryMovementLine.movement',
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeRequirement(
        ServicePartRequirementData $data
    ): array {
        $normalized = [
            'service_work_item_id' => $data->serviceWorkItemId,
            'service_quote_line_id' => $data->serviceQuoteLineId,
            'catalog_product_id' => $data->catalogProductId,
            'condition' => $data->condition->value,
            'source' => $data->source->value,
            'required_quantity' => InventoryQuantity::positive(
                $data->requiredQuantity
            ),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeWarrantyRequirement(
        ServiceWarrantyPartRequirementData $data
    ): array {
        $normalized = [
            'service_work_item_id' => $data->serviceWorkItemId,
            'service_warranty_claim_resolution_id' => $data->serviceWarrantyClaimResolutionId,
            'catalog_product_id' => $data->catalogProductId,
            'condition' => $data->condition->value,
            'source' => $data->source->value,
            'required_quantity' => InventoryQuantity::positive(
                $data->requiredQuantity
            ),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizePurchase(
        ServicePartPurchaseData $data
    ): array {
        if ($data->lines === []) {
            throw new DomainException(
                'La compra afectada requiere al menos una línea.'
            );
        }

        $currency = strtoupper(trim($data->currencyCode));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException(
                'La moneda debe expresarse con un código ISO de tres letras.'
            );
        }

        if ($data->logisticsCostMinor < 0) {
            throw new DomainException(
                'El costo logístico no puede ser negativo.'
            );
        }

        $lines = [];
        $requirementIds = [];
        $partsTotal = 0;

        foreach ($data->lines as $line) {
            if (! $line instanceof ServicePartPurchaseLineData) {
                throw new DomainException(
                    'Las líneas de la compra afectada no son válidas.'
                );
            }

            if (in_array(
                $line->servicePartRequirementId,
                $requirementIds,
                true
            )) {
                throw new DomainException(
                    'Una compra no puede repetir la misma necesidad de repuesto.'
                );
            }

            $requirementIds[] = $line->servicePartRequirementId;
            $quantity = InventoryQuantity::positive($line->quantity);
            $lineTotal = $this->lineTotalMinor(
                $quantity,
                $line->unitCostMinor
            );

            if ($partsTotal > PHP_INT_MAX - $lineTotal) {
                throw new DomainException(
                    'El total de repuestos supera el importe admitido.'
                );
            }

            $partsTotal += $lineTotal;
            $lines[] = [
                'service_part_requirement_id' => $line->servicePartRequirementId,
                'quantity' => $quantity,
                'unit_cost_minor' => $line->unitCostMinor,
                'line_total_minor' => $lineTotal,
            ];
        }

        if ($partsTotal > PHP_INT_MAX - $data->logisticsCostMinor) {
            throw new DomainException(
                'El total de la compra supera el importe admitido.'
            );
        }

        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'supplier_id' => $data->supplierId,
            'currency_code' => $currency,
            'purchased_at' => CarbonImmutable::instance(
                $data->purchasedAt
            )->toIso8601String(),
            'lines' => $lines,
            'parts_total_minor' => $partsTotal,
            'logistics_cost_minor' => $data->logisticsCostMinor,
            'grand_total_minor' => $partsTotal + $data->logisticsCostMinor,
            'document_reference' => $this->optional($data->documentReference),
            'notes' => $this->optional($data->notes),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeConsumption(
        ServicePartConsumptionData $data
    ): array {
        $normalized = [
            'service_part_requirement_id' => $data->servicePartRequirementId,
            'quantity' => InventoryQuantity::positive($data->quantity),
            'source_location_id' => $data->sourceLocationId,
            'service_part_purchase_line_id' => $data->servicePartPurchaseLineId,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    private function allDirectRequirementsPurchased(
        int $organizationId,
        ServiceOrder $order
    ): bool {
        $requirements = ServicePartRequirement::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $order->id)
            ->where('source', ServicePartSource::DirectPurchase->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($requirements->isEmpty()) {
            return false;
        }

        foreach ($requirements as $requirement) {
            $purchased = (string) ServicePartPurchaseLine::query()
                ->forOrganization($organizationId)
                ->where(
                    'service_part_requirement_id',
                    $requirement->id
                )
                ->sum('quantity');

            if (! InventoryQuantity::lessThanOrEqual(
                $requirement->required_quantity,
                $purchased
            )) {
                return false;
            }
        }

        return true;
    }

    private function transitionOrder(
        ServiceOrder $order,
        ServiceOrderStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt
    ): void {
        $from = $order->status;

        if (! $order->allowsTransitionTo($target)) {
            throw new DomainException(
                'La transición solicitada no es válida para la orden.'
            );
        }

        ServiceOrderStatusHistory::query()->create([
            'organization_id' => $order->organization_id,
            'service_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $target,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'changed_at' => $changedAt,
        ]);
        $order->status = $target;
        $order->save();
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

    private function guardActor(
        int $organizationId,
        User $actor,
        string $action
    ): void {
        if (! $actor->exists || $actor->trashed()) {
            throw new DomainException(
                'El usuario no puede registrar esta operación de repuestos.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();
        $allowed = match ($action) {
            'plan' => $membership?->role->canPlanServiceParts(),
            'purchase' => $membership?->role->canRecordServicePartPurchases(),
            'consume' => $membership?->role->canConsumeServiceParts(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El usuario no puede registrar esta operación de repuestos.'
            );
        }
    }

    private function lockedOrder(
        int $organizationId,
        int $orderId
    ): ServiceOrder {
        $order = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new DomainException(
                'La orden no pertenece a la organización activa.'
            );
        }

        return $order;
    }

    private function lockedWorkItem(
        int $organizationId,
        int $workItemId
    ): ServiceWorkItem {
        $item = ServiceWorkItem::query()
            ->forOrganization($organizationId)
            ->whereKey($workItemId)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new DomainException(
                'El trabajo no pertenece a la organización activa.'
            );
        }

        return $item;
    }

    private function lineTotalMinor(
        string $quantity,
        int $unitCostMinor
    ): int {
        if ($unitCostMinor < 0) {
            throw new DomainException(
                'El costo unitario no puede ser negativo.'
            );
        }

        try {
            $total = BigDecimal::of($quantity)
                ->multipliedBy($unitCostMinor)
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
                'La cantidad y el costo producen una fracción de centavo.',
                previous: $exception
            );
        }
    }

    private function required(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new DomainException($label.' es obligatorio.');
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = $this->required($value, 'La clave de idempotencia');

        if (mb_strlen($value) > 100) {
            throw new DomainException(
                'La clave de idempotencia supera los 100 caracteres.'
            );
        }

        return $value;
    }

    private function derivedKey(string $base, string $suffix): string
    {
        $candidate = $base.':'.$suffix;

        if (strlen($candidate) <= 100) {
            return $candidate;
        }

        return substr($base, 0, 58)
            .':'
            .substr(hash('sha256', $candidate), 0, 32)
            .':'
            .$suffix;
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
                'No se pudo consolidar la operación de repuestos.',
                previous: $exception
            );
        }
    }

    private function guardFingerprint(
        string $stored,
        string $expected,
        string $operation
    ): void {
        if (! hash_equals($stored, $expected)) {
            throw new DomainException(
                "La clave de idempotencia de {$operation} ya fue utilizada con otros datos."
            );
        }
    }
}
