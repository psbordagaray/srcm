<?php

namespace App\Domain\Knowledge;

use App\Models\Entity;

class KnowledgeEngine
{
    public function resolve(string $query): array
    {
        $entity = Entity::query()
            ->where('uuid', $query)
            ->orWhereHas('identifiers', function ($q) use ($query) {
                $q->where('value', $query);
            })
            ->with([
                'entityType',
                'identifiers.identifierType',
            ])
            ->first();

        if (! $entity) {
            return [
                'resolved' => false,
                'query' => $query,
            ];
        }

        return [
            'resolved' => true,
            'entity' => $entity,
        ];
    }
}