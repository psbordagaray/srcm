<?php

namespace App\Domain\Catalog;

use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticCapabilityStatus;
use App\Models\ProductSchemaCapabilityDeclaration;
use App\Models\ProductSchemaVersion;
use App\Models\SemanticCapabilityDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductSchemaCapabilityManager
{
    public function declare(
        ProductSchemaVersion $schema,
        SemanticCapabilityDefinition $capability,
        SemanticCapabilityActivationMode $activationMode,
        bool $defaultEnabled
    ): ProductSchemaCapabilityDeclaration {
        return DB::transaction(function () use (
            $schema,
            $capability,
            $activationMode,
            $defaultEnabled
        ): ProductSchemaCapabilityDeclaration {
            $lockedSchema = $this->lockDraft($schema);
            $lockedCapability = $this->lockActiveCapability($capability);

            $this->assertActivationConfiguration(
                $activationMode,
                $defaultEnabled
            );

            if (
                ProductSchemaCapabilityDeclaration::query()
                    ->where(
                        'product_schema_version_id',
                        $lockedSchema->id
                    )
                    ->where(
                        'semantic_capability_definition_id',
                        $lockedCapability->id
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'La capability ya está declarada en esta versión de schema.'
                );
            }

            return ProductSchemaCapabilityDeclaration::query()->create([
                'product_schema_version_id' => $lockedSchema->id,
                'semantic_capability_definition_id' =>
                    $lockedCapability->id,
                'activation_mode' => $activationMode,
                'default_enabled' => $defaultEnabled,
            ])->fresh();
        });
    }

    public function reconfigure(
        ProductSchemaCapabilityDeclaration $declaration,
        SemanticCapabilityActivationMode $activationMode,
        bool $defaultEnabled
    ): ProductSchemaCapabilityDeclaration {
        return DB::transaction(function () use (
            $declaration,
            $activationMode,
            $defaultEnabled
        ): ProductSchemaCapabilityDeclaration {
            $locked = ProductSchemaCapabilityDeclaration::query()
                ->whereKey($declaration->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockDraft(
                $locked->schemaVersion()->firstOrFail()
            );

            $this->lockActiveCapability(
                $locked->capabilityDefinition()->firstOrFail()
            );

            $this->assertActivationConfiguration(
                $activationMode,
                $defaultEnabled
            );

            $locked->fill([
                'activation_mode' => $activationMode,
                'default_enabled' => $defaultEnabled,
            ])->save();

            return $locked->fresh();
        });
    }

    public function remove(
        ProductSchemaCapabilityDeclaration $declaration
    ): void {
        DB::transaction(function () use ($declaration): void {
            $locked = ProductSchemaCapabilityDeclaration::query()
                ->whereKey($declaration->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockDraft(
                $locked->schemaVersion()->firstOrFail()
            );

            $locked->delete();
        });
    }

    private function lockDraft(
        ProductSchemaVersion $schema
    ): ProductSchemaVersion {
        $locked = ProductSchemaVersion::query()
            ->whereKey($schema->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== ProductSchemaStatus::Draft) {
            throw new DomainException(
                'Las capability declarations sólo pueden mutarse mientras el schema está en draft.'
            );
        }

        return $locked;
    }

    private function lockActiveCapability(
        SemanticCapabilityDefinition $capability
    ): SemanticCapabilityDefinition {
        $locked = SemanticCapabilityDefinition::query()
            ->whereKey($capability->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== SemanticCapabilityStatus::Active) {
            throw new DomainException(
                'Una capability declaration nueva o reconfigurada requiere una capability activa.'
            );
        }

        return $locked;
    }

    private function assertActivationConfiguration(
        SemanticCapabilityActivationMode $activationMode,
        bool $defaultEnabled
    ): void {
        if (
            $activationMode
                === SemanticCapabilityActivationMode::FixedEnabled
            && ! $defaultEnabled
        ) {
            throw new DomainException(
                'FIXED_ENABLED requiere default_enabled=true.'
            );
        }
    }
}
