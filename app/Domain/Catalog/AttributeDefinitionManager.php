<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeDefinitionStatus;
use App\Models\AttributeDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

class AttributeDefinitionManager
{
    public function create(
        string $key,
        string $name,
        ?string $description = null
    ): AttributeDefinition {
        SemanticKey::assertValid($key);
        $this->assertName($name);

        return DB::transaction(function () use (
            $key,
            $name,
            $description
        ): AttributeDefinition {
            if (
                AttributeDefinition::query()
                    ->where('key', $key)
                    ->exists()
            ) {
                throw new DomainException(
                    'La semantic key de la definición de atributo ya existe.'
                );
            }

            return AttributeDefinition::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'status' => AttributeDefinitionStatus::Active,
            ]);
        });
    }

    public function updateMetadata(
        AttributeDefinition $attribute,
        string $name,
        ?string $description = null
    ): AttributeDefinition {
        $this->assertName($name);

        return DB::transaction(function () use (
            $attribute,
            $name,
            $description
        ): AttributeDefinition {
            $locked = $this->lock($attribute);

            if ($locked->status === AttributeDefinitionStatus::Retired) {
                throw new DomainException(
                    'Una definición de atributo retirada es inmutable.'
                );
            }

            $locked->fill([
                'name' => $name,
                'description' => $description,
            ])->save();

            return $locked->fresh();
        });
    }

    public function deprecate(
        AttributeDefinition $attribute
    ): AttributeDefinition {
        return DB::transaction(function () use (
            $attribute
        ): AttributeDefinition {
            $locked = $this->lock($attribute);

            if ($locked->status !== AttributeDefinitionStatus::Active) {
                throw new DomainException(
                    'Sólo una definición de atributo activa puede deprecarse.'
                );
            }

            $locked->status = AttributeDefinitionStatus::Deprecated;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retire(
        AttributeDefinition $attribute
    ): AttributeDefinition {
        return DB::transaction(function () use (
            $attribute
        ): AttributeDefinition {
            $locked = $this->lock($attribute);

            if ($locked->status !== AttributeDefinitionStatus::Deprecated) {
                throw new DomainException(
                    'Sólo una definición de atributo deprecada puede retirarse.'
                );
            }

            $locked->status = AttributeDefinitionStatus::Retired;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lock(
        AttributeDefinition $attribute
    ): AttributeDefinition {
        return AttributeDefinition::query()
            ->whereKey($attribute->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertName(string $name): void
    {
        if (
            trim($name) === ''
            || mb_strlen($name) > 160
        ) {
            throw new DomainException(
                'El nombre de la definición de atributo es obligatorio '
                .'y admite hasta 160 caracteres.'
            );
        }
    }
}
