<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticCapabilityStatus;
use App\Models\SemanticCapabilityDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

class SemanticCapabilityDefinitionManager
{
    public function create(
        string $key,
        string $name,
        ?string $description = null
    ): SemanticCapabilityDefinition {
        SemanticKey::assertValid($key);
        $this->assertName($name);

        return DB::transaction(function () use (
            $key,
            $name,
            $description
        ): SemanticCapabilityDefinition {
            if (
                SemanticCapabilityDefinition::query()
                    ->where('key', $key)
                    ->exists()
            ) {
                throw new DomainException(
                    'La semantic key de la capability ya existe.'
                );
            }

            return SemanticCapabilityDefinition::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'status' => SemanticCapabilityStatus::Active,
            ]);
        });
    }

    public function updateMetadata(
        SemanticCapabilityDefinition $capability,
        string $name,
        ?string $description = null
    ): SemanticCapabilityDefinition {
        $this->assertName($name);

        return DB::transaction(function () use (
            $capability,
            $name,
            $description
        ): SemanticCapabilityDefinition {
            $locked = $this->lock($capability);

            if ($locked->status === SemanticCapabilityStatus::Retired) {
                throw new DomainException(
                    'Una capability semántica retirada es inmutable.'
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
        SemanticCapabilityDefinition $capability
    ): SemanticCapabilityDefinition {
        return DB::transaction(function () use (
            $capability
        ): SemanticCapabilityDefinition {
            $locked = $this->lock($capability);

            if ($locked->status !== SemanticCapabilityStatus::Active) {
                throw new DomainException(
                    'Sólo una capability semántica activa puede deprecarse.'
                );
            }

            $locked->status = SemanticCapabilityStatus::Deprecated;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retire(
        SemanticCapabilityDefinition $capability
    ): SemanticCapabilityDefinition {
        return DB::transaction(function () use (
            $capability
        ): SemanticCapabilityDefinition {
            $locked = $this->lock($capability);

            if ($locked->status !== SemanticCapabilityStatus::Deprecated) {
                throw new DomainException(
                    'Sólo una capability semántica deprecada puede retirarse.'
                );
            }

            $locked->status = SemanticCapabilityStatus::Retired;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lock(
        SemanticCapabilityDefinition $capability
    ): SemanticCapabilityDefinition {
        return SemanticCapabilityDefinition::query()
            ->whereKey($capability->getKey())
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
                'El nombre de la capability semántica es obligatorio '
                .'y admite hasta 160 caracteres.'
            );
        }
    }
}
