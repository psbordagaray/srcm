<?php

namespace App\Domain\Catalog;

use App\Enums\ProductDefinitionStatus;
use App\Enums\ProductSchemaStatus;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductSchemaVersionManager
{
    public function createDraft(
        ProductDefinition $definition,
        ?string $changeSummary = null
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $definition,
            $changeSummary
        ): ProductSchemaVersion {
            $lockedDefinition = $this->lockDefinition($definition);

            $this->assertDefinitionActive($lockedDefinition);

            if (
                ProductSchemaVersion::query()
                    ->where(
                        'product_definition_id',
                        $lockedDefinition->id
                    )
                    ->where('status', ProductSchemaStatus::Draft->value)
                    ->exists()
            ) {
                throw new DomainException(
                    'La definición de producto ya posee un draft abierto.'
                );
            }

            $maxVersion = ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $lockedDefinition->id
                )
                ->max('version');

            return ProductSchemaVersion::query()->create([
                'product_definition_id' => $lockedDefinition->id,
                'version' => ((int) $maxVersion) + 1,
                'status' => ProductSchemaStatus::Draft,
                'change_summary' => $changeSummary,
            ]);
        });
    }

    public function updateDraftMetadata(
        ProductSchemaVersion $version,
        ?string $changeSummary
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version,
            $changeSummary
        ): ProductSchemaVersion {
            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede modificar su change summary.'
                );
            }

            $locked->change_summary = $changeSummary;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function publish(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $definition = ProductDefinition::query()
                ->whereKey($version->product_definition_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDefinitionActive($definition);

            $locked = ProductSchemaVersion::query()
                ->whereKey($version->getKey())
                ->where(
                    'product_definition_id',
                    $definition->id
                )
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede publicarse.'
                );
            }

            $published = ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $definition->id
                )
                ->where(
                    'status',
                    ProductSchemaStatus::Published->value
                )
                ->lockForUpdate()
                ->get();

            if ($published->count() > 1) {
                throw new DomainException(
                    'La definición posee múltiples schemas publicados.'
                );
            }

            $now = now();

            if ($published->isNotEmpty()) {
                $current = $published->first();
                $current->status = ProductSchemaStatus::Deprecated;
                $current->deprecated_at = $now;
                $current->save();
            }

            $locked->status = ProductSchemaStatus::Published;
            $locked->published_at = $now;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function abandonDraft(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $this->lockDefinitionById(
                (int) $version->product_definition_id
            );

            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede abandonarse.'
                );
            }

            $locked->status = ProductSchemaStatus::Retired;
            $locked->retired_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retireDeprecated(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $this->lockDefinitionById(
                (int) $version->product_definition_id
            );

            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Deprecated) {
                throw new DomainException(
                    'Sólo una versión de schema deprecada puede retirarse.'
                );
            }

            $locked->status = ProductSchemaStatus::Retired;
            $locked->retired_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lockDefinition(
        ProductDefinition $definition
    ): ProductDefinition {
        return $this->lockDefinitionById(
            (int) $definition->getKey()
        );
    }

    private function lockDefinitionById(
        int $definitionId
    ): ProductDefinition {
        return ProductDefinition::query()
            ->whereKey($definitionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockVersion(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return ProductSchemaVersion::query()
            ->whereKey($version->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertDefinitionActive(
        ProductDefinition $definition
    ): void {
        if (
            $definition->status
                !== ProductDefinitionStatus::Active
        ) {
            throw new DomainException(
                'La definición de producto debe estar activa.'
            );
        }
    }
}
