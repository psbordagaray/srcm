<?php

namespace App\Domain\Knowledge;

use App\Models\CatalogProduct;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogProductKnowledgeManager
{
    /**
     * @param array{
     *     product_category_id: int|string,
     *     brand_id: int|string|null,
     *     manufacturer_id: int|string|null,
     *     sku: string,
     *     name: string,
     *     description: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function create(array $data): CatalogProduct
    {
        return DB::transaction(function () use ($data): CatalogProduct {
            $product = CatalogProduct::query()->create($data);

            return $this->synchronizeLocked(
                $product,
                adoptExisting: true
            );
        });
    }

    /**
     * @param array{
     *     product_category_id: int|string,
     *     brand_id: int|string|null,
     *     manufacturer_id: int|string|null,
     *     sku: string,
     *     name: string,
     *     description: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function update(
        CatalogProduct $product,
        array $data
    ): CatalogProduct {
        return DB::transaction(function () use (
            $product,
            $data
        ): CatalogProduct {
            $locked = CatalogProduct::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update($data);

            return $this->synchronizeLocked(
                $locked,
                adoptExisting: false
            );
        });
    }

    public function toggleActive(
        CatalogProduct $product
    ): CatalogProduct {
        return DB::transaction(function () use (
            $product
        ): CatalogProduct {
            $locked = CatalogProduct::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $this->synchronizeLocked(
                $locked,
                adoptExisting: false
            );
        });
    }

    private function synchronizeLocked(
        CatalogProduct $product,
        bool $adoptExisting
    ): CatalogProduct {
        $locked = CatalogProduct::query()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $hasEntity = $locked->knowledge_entity_id !== null;
        $hasIdentifier = $locked->knowledge_identifier_id !== null;

        if ($hasEntity !== $hasIdentifier) {
            throw new DomainException(
                'El producto posee un vínculo de conocimiento incompleto.'
            );
        }

        if ($hasEntity) {
            return $this->synchronizeLinked($locked);
        }

        if ($adoptExisting) {
            $candidate = $this->findAdoptableIdentifier($locked);

            if ($candidate) {
                $this->assertLinksAreAvailable(
                    $locked,
                    $candidate->entity,
                    $candidate
                );

                $locked->forceFill([
                    'knowledge_entity_id' => $candidate->entity_id,
                    'knowledge_identifier_id' => $candidate->getKey(),
                ])->save();

                return $this->synchronizeLinked($locked);
            }
        }

        return $this->createKnowledgeIdentity($locked);
    }

    private function synchronizeLinked(
        CatalogProduct $product
    ): CatalogProduct {
        $entity = Entity::query()
            ->whereKey($product->knowledge_entity_id)
            ->lockForUpdate()
            ->firstOrFail();

        $identifier = Identifier::query()
            ->whereKey($product->knowledge_identifier_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $identifier->entity_id !== (int) $entity->getKey()) {
            throw new DomainException(
                'El identificador vinculado no pertenece a la ficha vinculada.'
            );
        }

        $this->assertLinksAreAvailable(
            $product,
            $entity,
            $identifier
        );

        $entity->update([
            'name' => $product->name,
            'entity_type_id' => $this->entityType()->getKey(),
            'active' => (bool) $product->active,
        ]);

        $identifier->identifier_type_id =
            $this->identifierType()->getKey();
        $identifier->value = $product->sku;
        $identifier->is_primary = true;
        $identifier->active = true;
        $identifier->save();

        return $product->fresh($this->relations());
    }

    private function createKnowledgeIdentity(
        CatalogProduct $product
    ): CatalogProduct {
        $entity = Entity::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $product->name,
            'entity_type_id' => $this->entityType()->getKey(),
            'active' => (bool) $product->active,
        ]);

        $identifier = $entity->identifiers()->create([
            'identifier_type_id' => $this->identifierType()->getKey(),
            'value' => $product->sku,
            'is_primary' => true,
            'active' => true,
        ]);

        $product->forceFill([
            'knowledge_entity_id' => $entity->getKey(),
            'knowledge_identifier_id' => $identifier->getKey(),
        ])->save();

        return $product->fresh($this->relations());
    }

    private function findAdoptableIdentifier(
        CatalogProduct $product
    ): ?Identifier {
        $normalizedSku = app(
            IdentifierIntegrity::class
        )->normalize($product->sku);

        $matches = Identifier::query()
            ->where(
                'identifier_type_id',
                $this->identifierType()->getKey()
            )
            ->where('normalized_value', $normalizedSku)
            ->whereHas(
                'entity.entityType',
                fn ($query) => $query->where(
                    'slug',
                    'catalog-product'
                )
            )
            ->with('entity.entityType')
            ->orderByDesc('active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $activeMatches = $matches
            ->where('active', true)
            ->values();

        if ($activeMatches->count() > 1) {
            throw new DomainException(
                'Hay más de una ficha activa con este SKU. La vinculación requiere revisión manual.'
            );
        }

        if ($activeMatches->count() === 1) {
            return $activeMatches->first();
        }

        if ($matches->count() > 1) {
            throw new DomainException(
                'Hay más de una ficha histórica con este SKU. La vinculación requiere revisión manual.'
            );
        }

        return $matches->first();
    }

    private function entityType(): EntityType
    {
        $type = EntityType::query()
            ->where('slug', 'catalog-product')
            ->where('active', true)
            ->first();

        if (! $type) {
            throw new DomainException(
                'No existe el tipo activo catalog-product.'
            );
        }

        return $type;
    }

    private function identifierType(): IdentifierType
    {
        $type = IdentifierType::query()
            ->where('slug', 'main-code')
            ->where('active', true)
            ->first();

        if (! $type) {
            throw new DomainException(
                'No existe el tipo activo main-code.'
            );
        }

        return $type;
    }

    private function assertLinksAreAvailable(
        CatalogProduct $product,
        Entity $entity,
        Identifier $identifier
    ): void {
        if (
            CatalogProduct::query()
                ->whereKeyNot($product->getKey())
                ->where('knowledge_entity_id', $entity->getKey())
                ->exists()
        ) {
            throw new DomainException(
                'La ficha ya está vinculada a otro producto.'
            );
        }

        if (
            CatalogProduct::query()
                ->whereKeyNot($product->getKey())
                ->where('knowledge_identifier_id', $identifier->getKey())
                ->exists()
        ) {
            throw new DomainException(
                'El identificador ya está vinculado a otro producto.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'productCategory',
            'brand',
            'manufacturer',
            'knowledgeEntity.entityType',
            'knowledgeIdentifier.identifierType',
        ];
    }
}
