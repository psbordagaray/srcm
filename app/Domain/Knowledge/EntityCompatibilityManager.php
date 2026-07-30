<?php

namespace App\Domain\Knowledge;

use App\Enums\CompatibilityType;
use App\Models\Compatibility;
use App\Models\Entity;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EntityCompatibilityManager
{
    /**
     * @param array{
     *     related_entity_uuid: string,
     *     relationship_type: string,
     *     confidence: int,
     *     source: ?string,
     *     evidence: ?string
     * } $data
     */
    public function create(
        Entity $entity,
        array $data
    ): Compatibility {
        return DB::transaction(function () use (
            $entity,
            $data
        ): Compatibility {
            $relatedEntity = Entity::query()
                ->where('uuid', $data['related_entity_uuid'])
                ->first();

            if (! $relatedEntity) {
                throw new DomainException(
                    'La entidad relacionada no existe.'
                );
            }

            if (
                (int) $entity->getKey()
                === (int) $relatedEntity->getKey()
            ) {
                throw new DomainException(
                    'Una entidad no puede relacionarse consigo misma.'
                );
            }

            $lockedEntities = $this->lockEntities(
                $entity,
                $relatedEntity
            );

            if (
                ! $lockedEntities
                    ->every(fn (Entity $item): bool => $item->active)
            ) {
                throw new DomainException(
                    'Las dos entidades deben estar activas para crear la relación.'
                );
            }

            $type = CompatibilityType::from(
                $data['relationship_type']
            );

            [$leftEntityId, $rightEntityId] = $this->relationSides(
                $entity,
                $relatedEntity,
                $type
            );

            $existing = Compatibility::query()
                ->where('left_entity_id', $leftEntityId)
                ->where('right_entity_id', $rightEntityId)
                ->where('relationship_type', $type->value)
                ->lockForUpdate()
                ->first();

            if ($existing?->active) {
                throw new DomainException(
                    'Esta relación activa ya está registrada.'
                );
            }

            $attributes = [
                'confidence' => (int) $data['confidence'],
                'source' => $data['source'],
                'evidence' => $data['evidence'],
                'active' => true,
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing->fresh([
                    'leftEntity.entityType',
                    'rightEntity.entityType',
                ]);
            }

            return Compatibility::query()
                ->create([
                    'left_entity_id' => $leftEntityId,
                    'right_entity_id' => $rightEntityId,
                    'relationship_type' => $type->value,
                    ...$attributes,
                ])
                ->load([
                    'leftEntity.entityType',
                    'rightEntity.entityType',
                ]);
        });
    }

    public function toggleActive(
        Entity $entity,
        Compatibility $compatibility
    ): Compatibility {
        $this->assertBelongsToEntity(
            $entity,
            $compatibility
        );

        return DB::transaction(function () use (
            $entity,
            $compatibility
        ): Compatibility {
            $target = Compatibility::query()
                ->whereKey($compatibility->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertBelongsToEntity(
                $entity,
                $target
            );

            $leftEntity = Entity::query()
                ->findOrFail($target->left_entity_id);

            $rightEntity = Entity::query()
                ->findOrFail($target->right_entity_id);

            $lockedEntities = $this->lockEntities(
                $leftEntity,
                $rightEntity
            );

            if (
                ! $target->active
                && ! $lockedEntities
                    ->every(fn (Entity $item): bool => $item->active)
            ) {
                throw new DomainException(
                    'No se puede reactivar la relación mientras alguna entidad esté inactiva.'
                );
            }

            $target->update([
                'active' => ! $target->active,
            ]);

            return $target->fresh([
                'leftEntity.entityType',
                'rightEntity.entityType',
            ]);
        });
    }

    /**
     * @return Collection<int, Entity>
     */
    private function lockEntities(
        Entity $first,
        Entity $second
    ): Collection {
        return Entity::query()
            ->whereIn('id', [
                $first->getKey(),
                $second->getKey(),
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function relationSides(
        Entity $entity,
        Entity $relatedEntity,
        CompatibilityType $type
    ): array {
        $leftEntityId = (int) $entity->getKey();
        $rightEntityId = (int) $relatedEntity->getKey();

        if (
            $type->isSymmetric()
            && $leftEntityId > $rightEntityId
        ) {
            return [
                $rightEntityId,
                $leftEntityId,
            ];
        }

        return [
            $leftEntityId,
            $rightEntityId,
        ];
    }

    private function assertBelongsToEntity(
        Entity $entity,
        Compatibility $compatibility
    ): void {
        $entityId = (int) $entity->getKey();

        if (
            $entityId !== (int) $compatibility->left_entity_id
            && $entityId !== (int) $compatibility->right_entity_id
        ) {
            abort(404);
        }
    }
}
