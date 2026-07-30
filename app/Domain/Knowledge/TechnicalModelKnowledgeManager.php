<?php

namespace App\Domain\Knowledge;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use App\Models\TechnicalModel;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TechnicalModelKnowledgeManager
{
    /**
     * @var array<string, list<string>>
     */
    private const ENTITY_TYPE_ALIASES = [
        'tv' => [
            'televisor',
            'technical-model',
        ],
        'televisor' => [
            'televisor',
            'technical-model',
        ],
        'televisores' => [
            'televisor',
            'technical-model',
        ],
        'control-remoto' => [
            'control-remoto',
            'remote-control',
            'technical-model',
        ],
        'control-remotos' => [
            'control-remoto',
            'remote-control',
            'technical-model',
        ],
        'remote-control' => [
            'remote-control',
            'control-remoto',
            'technical-model',
        ],
    ];

    /**
     * @var list<string>
     */
    private const MODEL_IDENTIFIER_SLUGS = [
        'model-code',
        'modelo-tecnico',
        'technical-model',
    ];

    /**
     * @param array{
     *     brand_id: int|string,
     *     product_category_id: int|string,
     *     code: string,
     *     name: ?string,
     *     description: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function create(array $data): TechnicalModel
    {
        return DB::transaction(function () use ($data): TechnicalModel {
            $technicalModel = TechnicalModel::query()->create($data);

            return $this->synchronizeLocked(
                $technicalModel,
                adoptExisting: false,
                preserveExistingName: false
            );
        });
    }

    /**
     * @param array{
     *     brand_id: int|string,
     *     product_category_id: int|string,
     *     code: string,
     *     name: ?string,
     *     description: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function update(
        TechnicalModel $technicalModel,
        array $data
    ): TechnicalModel {
        return DB::transaction(function () use (
            $technicalModel,
            $data
        ): TechnicalModel {
            $locked = TechnicalModel::query()
                ->whereKey($technicalModel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update($data);

            return $this->synchronizeLocked(
                $locked,
                adoptExisting: false,
                preserveExistingName: false
            );
        });
    }

    public function toggleActive(
        TechnicalModel $technicalModel
    ): TechnicalModel {
        return DB::transaction(function () use (
            $technicalModel
        ): TechnicalModel {
            $locked = TechnicalModel::query()
                ->whereKey($technicalModel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $this->synchronizeLocked(
                $locked,
                adoptExisting: false,
                preserveExistingName: false
            );
        });
    }

    public function bridgeExisting(
        TechnicalModel $technicalModel
    ): TechnicalModel {
        return DB::transaction(
            fn (): TechnicalModel => $this->synchronizeLocked(
                $technicalModel,
                adoptExisting: true,
                preserveExistingName: true
            )
        );
    }

    private function synchronizeLocked(
        TechnicalModel $technicalModel,
        bool $adoptExisting,
        bool $preserveExistingName
    ): TechnicalModel {
        $locked = TechnicalModel::query()
            ->whereKey($technicalModel->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $locked->load([
            'brand',
            'productCategory',
        ]);

        $hasEntityLink =
            $locked->knowledge_entity_id !== null;

        $hasIdentifierLink =
            $locked->knowledge_identifier_id !== null;

        if ($hasEntityLink !== $hasIdentifierLink) {
            throw new DomainException(
                'El modelo técnico posee un vínculo de conocimiento incompleto.'
            );
        }

        if ($hasEntityLink) {
            return $this->synchronizeLinked(
                $locked,
                preserveExistingName: false
            );
        }

        if ($adoptExisting) {
            $candidate = $this->findAdoptableIdentifier(
                $locked
            );

            if ($candidate) {
                $this->assertLinksAreAvailable(
                    $locked,
                    $candidate->entity,
                    $candidate
                );

                $locked->forceFill([
                    'knowledge_entity_id' =>
                        $candidate->entity_id,
                    'knowledge_identifier_id' =>
                        $candidate->getKey(),
                ])->save();

                return $this->synchronizeLinked(
                    $locked,
                    $preserveExistingName
                );
            }
        }

        return $this->createKnowledgeIdentity($locked);
    }

    private function synchronizeLinked(
        TechnicalModel $technicalModel,
        bool $preserveExistingName
    ): TechnicalModel {
        $entity = Entity::query()
            ->whereKey($technicalModel->knowledge_entity_id)
            ->lockForUpdate()
            ->firstOrFail();

        $identifier = Identifier::query()
            ->whereKey($technicalModel->knowledge_identifier_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            (int) $identifier->entity_id
            !== (int) $entity->getKey()
        ) {
            throw new DomainException(
                'El identificador vinculado no pertenece a la entidad vinculada.'
            );
        }

        $this->assertLinksAreAvailable(
            $technicalModel,
            $entity,
            $identifier
        );

        $entityAttributes = [
            'entity_type_id' =>
                $this->resolveEntityType($technicalModel)->getKey(),
            'active' => (bool) $technicalModel->active,
        ];

        if (! $preserveExistingName) {
            $entityAttributes['name'] =
                $this->displayName($technicalModel);
        }

        $entity->update($entityAttributes);

        $identifier->value = trim(
            (string) $technicalModel->code
        );
        $identifier->active = true;
        $identifier->save();

        return $technicalModel->fresh([
            'brand',
            'productCategory',
            'knowledgeEntity.entityType',
            'knowledgeIdentifier.identifierType',
        ]);
    }

    private function createKnowledgeIdentity(
        TechnicalModel $technicalModel
    ): TechnicalModel {
        $entity = Entity::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $this->displayName($technicalModel),
            'entity_type_id' =>
                $this->resolveEntityType($technicalModel)->getKey(),
            'active' => (bool) $technicalModel->active,
        ]);

        $identifier = $entity->identifiers()->create([
            'identifier_type_id' =>
                $this->preferredIdentifierType()->getKey(),
            'value' => trim((string) $technicalModel->code),
            'is_primary' => true,
            'active' => true,
        ]);

        $technicalModel->forceFill([
            'knowledge_entity_id' => $entity->getKey(),
            'knowledge_identifier_id' =>
                $identifier->getKey(),
        ])->save();

        return $technicalModel->fresh([
            'brand',
            'productCategory',
            'knowledgeEntity.entityType',
            'knowledgeIdentifier.identifierType',
        ]);
    }

    private function findAdoptableIdentifier(
        TechnicalModel $technicalModel
    ): ?Identifier {
        $identifierTypeIds = $this
            ->modelIdentifierTypes()
            ->modelKeys();

        $normalizedCode = app(
            IdentifierIntegrity::class
        )->normalize((string) $technicalModel->code);

        $matches = Identifier::query()
            ->whereIn(
                'identifier_type_id',
                $identifierTypeIds
            )
            ->where(
                'normalized_value',
                $normalizedCode
            )
            ->with('entity')
            ->orderByDesc('active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(
                fn (Identifier $identifier): bool =>
                    $identifier->entity !== null
            )
            ->values();

        $activeMatches = $matches
            ->where('active', true)
            ->values();

        if ($activeMatches->count() > 1) {
            throw new DomainException(
                'Hay más de una ficha activa con este código. La vinculación requiere revisión manual.'
            );
        }

        if ($activeMatches->count() === 1) {
            return $activeMatches->first();
        }

        if ($matches->count() > 1) {
            throw new DomainException(
                'Hay más de una ficha histórica con este código. La vinculación requiere revisión manual.'
            );
        }

        return $matches->first();
    }

    /**
     * @return Collection<int, IdentifierType>
     */
    private function modelIdentifierTypes(): Collection
    {
        $types = IdentifierType::query()
            ->where('active', true)
            ->whereIn('slug', self::MODEL_IDENTIFIER_SLUGS)
            ->get();

        if ($types->isEmpty()) {
            throw new DomainException(
                'No existe un tipo activo para códigos de modelo.'
            );
        }

        return $types;
    }

    private function preferredIdentifierType(): IdentifierType
    {
        $types = $this
            ->modelIdentifierTypes()
            ->keyBy('slug');

        foreach (self::MODEL_IDENTIFIER_SLUGS as $slug) {
            $type = $types->get($slug);

            if ($type) {
                return $type;
            }
        }

        throw new DomainException(
            'No existe un tipo activo para códigos de modelo.'
        );
    }

    private function resolveEntityType(
        TechnicalModel $technicalModel
    ): EntityType {
        $categorySlug = trim(
            (string) $technicalModel
                ->productCategory
                ?->slug
        );

        $candidates = array_values(
            array_unique(
                array_filter([
                    $categorySlug,
                    ...(
                        self::ENTITY_TYPE_ALIASES[
                            $categorySlug
                        ] ?? []
                    ),
                    'technical-model',
                ])
            )
        );

        $types = EntityType::query()
            ->where('active', true)
            ->whereIn('slug', $candidates)
            ->get()
            ->keyBy('slug');

        foreach ($candidates as $slug) {
            $type = $types->get($slug);

            if ($type) {
                return $type;
            }
        }

        throw new DomainException(
            'No existe un tipo de entidad activo para esta categoría.'
        );
    }

    private function displayName(
        TechnicalModel $technicalModel
    ): string {
        $explicitName = trim(
            (string) $technicalModel->name
        );

        if ($explicitName !== '') {
            return $explicitName;
        }

        $brand = trim(
            (string) $technicalModel->brand?->name
        );

        return trim(
            $brand.' '.trim((string) $technicalModel->code)
        );
    }

    private function assertLinksAreAvailable(
        TechnicalModel $technicalModel,
        Entity $entity,
        Identifier $identifier
    ): void {
        $otherModelUsesEntity = TechnicalModel::query()
            ->whereKeyNot($technicalModel->getKey())
            ->where(
                'knowledge_entity_id',
                $entity->getKey()
            )
            ->exists();

        if ($otherModelUsesEntity) {
            throw new DomainException(
                'La ficha de conocimiento ya está vinculada a otro modelo técnico.'
            );
        }

        $otherModelUsesIdentifier = TechnicalModel::query()
            ->whereKeyNot($technicalModel->getKey())
            ->where(
                'knowledge_identifier_id',
                $identifier->getKey()
            )
            ->exists();

        if ($otherModelUsesIdentifier) {
            throw new DomainException(
                'El identificador ya está vinculado a otro modelo técnico.'
            );
        }
    }
}
