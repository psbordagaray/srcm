<?php

namespace App\Domain\Knowledge;

use App\Models\Identifier;
use App\Models\IdentifierType;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class IdentifierIntegrity
{
    public function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public function assertCanPersist(Identifier $identifier): void
    {
        if (! $identifier->active) {
            return;
        }

        if ($identifier->normalized_value === '') {
            throw new DomainException(
                'El identificador no puede quedar vacío.'
            );
        }

        $identifierType = IdentifierType::query()
            ->find($identifier->identifier_type_id);

        if (! $identifierType || ! $identifierType->active) {
            throw new DomainException(
                'El tipo de identificador no existe o está inactivo.'
            );
        }

        $matchingIdentifiers = Identifier::query()
            ->where('active', true)
            ->where(
                'identifier_type_id',
                $identifier->identifier_type_id
            )
            ->where(
                'normalized_value',
                $identifier->normalized_value
            )
            ->when(
                $identifier->exists,
                fn (Builder $query) => $query->whereKeyNot(
                    $identifier->getKey()
                )
            );

        if (
            (clone $matchingIdentifiers)
                ->where('entity_id', $identifier->entity_id)
                ->exists()
        ) {
            throw new DomainException(
                'La entidad ya posee este identificador activo.'
            );
        }

        if (
            $identifierType->is_unique
            && $matchingIdentifiers->exists()
        ) {
            throw new DomainException(
                'Este identificador es único y ya pertenece a otra entidad.'
            );
        }

        if (! $identifier->is_primary) {
            return;
        }

        $hasAnotherPrimary = Identifier::query()
            ->where('active', true)
            ->where('is_primary', true)
            ->where('entity_id', $identifier->entity_id)
            ->when(
                $identifier->exists,
                fn (Builder $query) => $query->whereKeyNot(
                    $identifier->getKey()
                )
            )
            ->exists();

        if ($hasAnotherPrimary) {
            throw new DomainException(
                'La entidad ya posee otro identificador principal activo.'
            );
        }
    }
}
