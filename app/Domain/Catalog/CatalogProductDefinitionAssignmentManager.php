<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditRecorder;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductDefinitionStatus;
use App\Enums\ProductSchemaStatus;
use App\Models\CatalogProduct;
use App\Models\CatalogProductSemanticValue;
use App\Models\FractionalContainer;
use App\Models\FulfillmentPreference;
use App\Models\InventoryReservation;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use App\Models\VariableQuantityFulfillment;
use DomainException;
use Illuminate\Support\Facades\DB;

class CatalogProductDefinitionAssignmentManager
{
    public function __construct(
        private readonly EffectiveSemanticProfileResolver $profileResolver,
        private readonly AuditRecorder $auditRecorder
    ) {
    }

    public function classify(
        CatalogProduct $product,
        ProductDefinition $target
    ): CatalogProduct {
        return DB::transaction(function () use (
            $product,
            $target
        ): CatalogProduct {
            $lockedProduct = $this->lockProduct($product);
            $currentId = $this->currentDefinitionId($lockedProduct);

            if ($currentId !== null) {
                if ($currentId === (int) $target->getKey()) {
                    return $lockedProduct->fresh();
                }

                throw new DomainException(
                    'El producto ya posee una clasificación semántica. '
                    .'Use la reclasificación controlada.'
                );
            }

            [
                $lockedTarget,
                $targetProfile,
            ] = $this->lockAndValidateTarget($target);

            $this->persistAssignment(
                $lockedProduct,
                $lockedTarget,
                $targetProfile,
                event: 'catalog_product.semantic_definition_assigned',
                oldValues: [
                    'product_definition_id' => null,
                    'product_definition_key' => null,
                ],
                reason: null
            );

            return $lockedProduct->fresh();
        });
    }

    public function reclassify(
        CatalogProduct $product,
        ProductDefinition $target,
        string $reason
    ): CatalogProduct {
        return DB::transaction(function () use (
            $product,
            $target,
            $reason
        ): CatalogProduct {
            $lockedProduct = $this->lockProduct($product);
            $currentId = $this->currentDefinitionId($lockedProduct);

            if ($currentId === null) {
                throw new DomainException(
                    'Un producto sin clasificación debe clasificarse antes '
                    .'de poder reclasificarse.'
                );
            }

            if ($currentId === (int) $target->getKey()) {
                return $lockedProduct->fresh();
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new DomainException(
                    'La reclasificación semántica requiere un motivo explícito.'
                );
            }

            $currentDefinition = ProductDefinition::query()
                ->whereKey($currentId)
                ->first();

            if (
                ! $currentDefinition
                || $currentDefinition->status
                    === ProductDefinitionStatus::Retired
            ) {
                throw new DomainException(
                    'La clasificación semántica actual está corrupta '
                    .'o retirada.'
                );
            }

            SemanticKey::assertValid(
                (string) $currentDefinition->key
            );

            [
                $lockedTarget,
                $targetProfile,
            ] = $this->lockAndValidateTarget($target);

            $this->assertReclassificationAllowed($lockedProduct);

            $this->persistAssignment(
                $lockedProduct,
                $lockedTarget,
                $targetProfile,
                event: 'catalog_product.semantic_definition_reclassified',
                oldValues: [
                    'product_definition_id' => $currentDefinition->id,
                    'product_definition_key' => $currentDefinition->key,
                ],
                reason: $reason
            );

            return $lockedProduct->fresh();
        });
    }

    private function lockProduct(
        CatalogProduct $product
    ): CatalogProduct {
        return CatalogProduct::query()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{ProductDefinition, EffectiveSemanticProfile}
     */
    private function lockAndValidateTarget(
        ProductDefinition $target
    ): array {
        $lockedTarget = ProductDefinition::query()
            ->whereKey($target->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        SemanticKey::assertValid(
            (string) $lockedTarget->key
        );

        if (
            $lockedTarget->status
            !== ProductDefinitionStatus::Active
        ) {
            throw new DomainException(
                'Sólo una definición de producto activa puede recibir '
                .'una nueva clasificación.'
            );
        }

        $published = ProductSchemaVersion::query()
            ->where(
                'product_definition_id',
                $lockedTarget->id
            )
            ->where(
                'status',
                ProductSchemaStatus::Published->value
            )
            ->lockForUpdate()
            ->get();

        if ($published->count() !== 1) {
            throw new DomainException(
                'La definición de producto objetivo debe poseer exactamente '
                .'un schema publicado actual.'
            );
        }

        $profile = $this->profileResolver
            ->currentPublished($lockedTarget);

        if (
            $profile->productSchemaVersionId
            !== (int) $published->sole()->id
        ) {
            throw new DomainException(
                'La resolución semántica objetivo no converge con el schema '
                .'publicado bloqueado.'
            );
        }

        return [
            $lockedTarget,
            $profile,
        ];
    }

    private function currentDefinitionId(
        CatalogProduct $product
    ): ?int {
        $value = $product->getAttribute(
            'product_definition_id'
        );

        if ($value === null) {
            return null;
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new DomainException(
                'La clasificación semántica almacenada del producto '
                .'es inválida.'
            );
        }

        return $id;
    }

    private function assertReclassificationAllowed(
        CatalogProduct $product
    ): void {
        if (
            CatalogProductSemanticValue::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->exists()
        ) {
            throw new DomainException(
                'El producto posee valores semánticos y no puede '
                .'reclasificarse hasta revisarlos y descartarlos explícitamente.'
            );
        }

        if (
            InventoryReservation::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->where(
                    'status',
                    InventoryReservationStatus::Active->value
                )
                ->where(function ($query): void {
                    $query
                        ->whereNull('expires_at')
                        ->orWhere(
                            'expires_at',
                            '>',
                            now()
                        );
                })
                ->exists()
        ) {
            throw new DomainException(
                'El producto posee una reserva efectiva y no puede '
                .'reclasificarse.'
            );
        }

        if (
            FractionalContainer::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->exists()
        ) {
            throw new DomainException(
                'El producto posee contenedores fraccionarios y no puede '
                .'reclasificarse.'
            );
        }

        if (
            VariableQuantityFulfillment::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->exists()
        ) {
            throw new DomainException(
                'El producto posee evidencia de fulfillment de cantidad '
                .'variable y no puede reclasificarse.'
            );
        }

        if (
            FulfillmentPreference::query()
                ->whereHas(
                    'reservation',
                    fn ($query) => $query->where(
                        'catalog_product_id',
                        $product->id
                    )
                )
                ->exists()
        ) {
            throw new DomainException(
                'El producto posee preferencias de fulfillment vinculadas '
                .'y no puede reclasificarse.'
            );
        }
    }

    private function persistAssignment(
        CatalogProduct $product,
        ProductDefinition $target,
        EffectiveSemanticProfile $targetProfile,
        string $event,
        array $oldValues,
        ?string $reason
    ): void {
        $newValues = [
            'product_definition_id' => $target->id,
            'product_definition_key' => $target->key,
            'product_schema_version_id' =>
                $targetProfile->productSchemaVersionId,
        ];

        if ($reason !== null) {
            $newValues['reason'] = $reason;
        }

        $product
            ->forceFill([
                'product_definition_id' => $target->id,
            ])
            ->saveQuietly();

        $this->auditRecorder->record(
            $product,
            $event,
            $oldValues,
            $newValues
        );
    }
}
