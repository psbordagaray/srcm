<?php

namespace App\Domain\Knowledge;

use App\Models\Entity;
use App\Models\Identifier;
use DomainException;
use Illuminate\Support\Facades\DB;

class EntityIdentifierManager
{
    /**
     * @param array{
     *     identifier_type_id: int,
     *     identifier_value: string
     * } $data
     */
    public function add(Entity $entity, array $data): Identifier
    {
        return DB::transaction(function () use ($entity, $data): Identifier {
            $lockedEntity = Entity::query()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $hasActivePrimary = Identifier::query()
                ->where('entity_id', $lockedEntity->getKey())
                ->where('active', true)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->exists();

            $identifier = $lockedEntity->identifiers()->create([
                'identifier_type_id' =>
                    (int) $data['identifier_type_id'],
                'value' => trim($data['identifier_value']),
                'is_primary' => ! $hasActivePrimary,
                'active' => true,
            ]);

            return $identifier->load('identifierType');
        });
    }

    public function makePrimary(
        Entity $entity,
        Identifier $identifier
    ): Identifier {
        $this->assertBelongsToEntity($entity, $identifier);

        return DB::transaction(function () use (
            $entity,
            $identifier
        ): Identifier {
            Entity::query()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $target = Identifier::query()
                ->whereKey($identifier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertBelongsToEntity($entity, $target);

            if (! $target->active) {
                throw new DomainException(
                    'No se puede marcar como principal un identificador inactivo.'
                );
            }

            if ($target->is_primary) {
                return $target->load('identifierType');
            }

            $currentPrimaries = Identifier::query()
                ->where('entity_id', $entity->getKey())
                ->where('active', true)
                ->where('is_primary', true)
                ->whereKeyNot($target->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($currentPrimaries as $currentPrimary) {
                $currentPrimary->update([
                    'is_primary' => false,
                ]);
            }

            $target->update([
                'is_primary' => true,
            ]);

            return $target->fresh('identifierType');
        });
    }

    public function toggleActive(
        Entity $entity,
        Identifier $identifier
    ): Identifier {
        $this->assertBelongsToEntity($entity, $identifier);

        return DB::transaction(function () use (
            $entity,
            $identifier
        ): Identifier {
            Entity::query()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $target = Identifier::query()
                ->whereKey($identifier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertBelongsToEntity($entity, $target);

            if ($target->active) {
                if ($target->is_primary) {
                    throw new DomainException(
                        'Marcá primero otro identificador como principal antes de inactivar este código.'
                    );
                }

                $activeCount = Identifier::query()
                    ->where('entity_id', $entity->getKey())
                    ->where('active', true)
                    ->lockForUpdate()
                    ->count();

                if ($activeCount <= 1) {
                    throw new DomainException(
                        'La entidad debe conservar al menos un identificador activo.'
                    );
                }

                $target->update([
                    'active' => false,
                    'is_primary' => false,
                ]);

                return $target->fresh('identifierType');
            }

            $hasActivePrimary = Identifier::query()
                ->where('entity_id', $entity->getKey())
                ->where('active', true)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->exists();

            $target->is_primary = ! $hasActivePrimary;
            $target->active = true;
            $target->save();

            return $target->fresh('identifierType');
        });
    }

    private function assertBelongsToEntity(
        Entity $entity,
        Identifier $identifier
    ): void {
        if (
            (int) $identifier->entity_id
            !== (int) $entity->getKey()
        ) {
            abort(404);
        }
    }
}
