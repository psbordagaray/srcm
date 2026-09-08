<?php

namespace App\Domain\Catalog;

use App\Enums\ProductDefinitionStatus;
use App\Models\ProductDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductDefinitionManager
{
    public function create(
        string $key,
        string $name,
        ?string $description = null
    ): ProductDefinition {
        SemanticKey::assertValid($key);
        $this->assertName($name);

        return DB::transaction(function () use (
            $key,
            $name,
            $description
        ): ProductDefinition {
            if (
                ProductDefinition::query()
                    ->where('key', $key)
                    ->exists()
            ) {
                throw new DomainException(
                    'La semantic key de la definición de producto ya existe.'
                );
            }

            return ProductDefinition::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'status' => ProductDefinitionStatus::Active,
            ]);
        });
    }

    public function updateMetadata(
        ProductDefinition $definition,
        string $name,
        ?string $description = null
    ): ProductDefinition {
        $this->assertName($name);

        return DB::transaction(function () use (
            $definition,
            $name,
            $description
        ): ProductDefinition {
            $locked = $this->lock($definition);

            if ($locked->status === ProductDefinitionStatus::Retired) {
                throw new DomainException(
                    'Una definición de producto retirada es inmutable.'
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
        ProductDefinition $definition
    ): ProductDefinition {
        return DB::transaction(function () use (
            $definition
        ): ProductDefinition {
            $locked = $this->lock($definition);

            if ($locked->status !== ProductDefinitionStatus::Active) {
                throw new DomainException(
                    'Sólo una definición de producto activa puede deprecarse.'
                );
            }

            $locked->status = ProductDefinitionStatus::Deprecated;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retire(
        ProductDefinition $definition
    ): ProductDefinition {
        return DB::transaction(function () use (
            $definition
        ): ProductDefinition {
            $locked = $this->lock($definition);

            if ($locked->status !== ProductDefinitionStatus::Deprecated) {
                throw new DomainException(
                    'Sólo una definición de producto deprecada puede retirarse.'
                );
            }

            $locked->status = ProductDefinitionStatus::Retired;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lock(
        ProductDefinition $definition
    ): ProductDefinition {
        return ProductDefinition::query()
            ->whereKey($definition->getKey())
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
                'El nombre de la definición de producto es obligatorio '
                .'y admite hasta 160 caracteres.'
            );
        }
    }
}
