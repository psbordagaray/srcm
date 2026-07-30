<?php

namespace App\Domain\Knowledge;

use App\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateEntityWithInitialIdentifier
{
    /**
     * @param array{
     *     name: string,
     *     entity_type_id: int,
     *     identifier_type_id: int,
     *     identifier_value: string
     * } $data
     */
    public function execute(array $data): Entity
    {
        return DB::transaction(function () use ($data): Entity {
            $entity = Entity::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => trim($data['name']),
                'entity_type_id' => (int) $data['entity_type_id'],
                'active' => true,
            ]);

            $entity->identifiers()->create([
                'identifier_type_id' => (int) $data['identifier_type_id'],
                'value' => trim($data['identifier_value']),
                'is_primary' => true,
                'active' => true,
            ]);

            return $entity->load([
                'entityType',
                'identifiers.identifierType',
            ]);
        });
    }
}
