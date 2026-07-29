<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\KnowledgeEngine;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentifierIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_identifier_value_is_trimmed_and_normalized(): void
    {
        [$entity, $identifierType] = $this->foundation();

        $identifier = Identifier::query()->create([
            'entity_id' => $entity->id,
            'identifier_type_id' => $identifierType->id,
            'value' => '  AKB75095308  ',
            'is_primary' => true,
            'active' => true,
        ]);

        $this->assertSame(
            'AKB75095308',
            $identifier->value
        );

        $this->assertSame(
            'akb75095308',
            $identifier->normalized_value
        );
    }

    public function test_single_exact_identifier_preserves_resolution_contract(): void
    {
        [$entity, $identifierType] = $this->foundation();

        $this->createIdentifier(
            $entity,
            $identifierType,
            'EN2BC27'
        );

        $result = app(KnowledgeEngine::class)
            ->resolve('en2bc27');

        $this->assertSame('resolved', $result['status']);
        $this->assertTrue($result['resolved']);
        $this->assertSame('exact', $result['match_type']);
        $this->assertSame('identifier', $result['matched_by']);
        $this->assertSame(
            $entity->uuid,
            $result['entity']->uuid
        );
    }

    public function test_unique_identifier_cannot_belong_to_two_entities(): void
    {
        [$firstEntity, $identifierType] = $this->foundation(
            isUnique: true
        );

        $secondEntity = $this->createEntity(
            $firstEntity->entity_type_id,
            'Control alternativo'
        );

        $this->createIdentifier(
            $firstEntity,
            $identifierType,
            'AKB75095308'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Este identificador es único'
        );

        $this->createIdentifier(
            $secondEntity,
            $identifierType,
            ' akb75095308 '
        );
    }

    public function test_same_entity_cannot_repeat_active_identifier(): void
    {
        [$entity, $identifierType] = $this->foundation();

        $this->createIdentifier(
            $entity,
            $identifierType,
            '43LM6300'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La entidad ya posee este identificador activo.'
        );

        $this->createIdentifier(
            $entity,
            $identifierType,
            ' 43lm6300 '
        );
    }

    public function test_entity_cannot_have_two_active_primary_identifiers(): void
    {
        [$entity, $identifierType] = $this->foundation();

        $this->createIdentifier(
            $entity,
            $identifierType,
            'PRIMARY-1',
            true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'otro identificador principal activo'
        );

        $this->createIdentifier(
            $entity,
            $identifierType,
            'PRIMARY-2',
            true
        );
    }

    public function test_non_unique_exact_identifier_returns_candidates(): void
    {
        [$firstEntity, $identifierType] = $this->foundation();

        $secondEntity = $this->createEntity(
            $firstEntity->entity_type_id,
            'Televisor dormitorio'
        );

        $this->createIdentifier(
            $firstEntity,
            $identifierType,
            '43LM6300'
        );

        $this->createIdentifier(
            $secondEntity,
            $identifierType,
            '43lm6300'
        );

        $result = app(KnowledgeEngine::class)
            ->resolve(' 43LM6300 ');

        $this->assertSame(
            'candidates',
            $result['status']
        );

        $this->assertFalse($result['resolved']);

        $this->assertSame(
            'ambiguous_exact_identifier',
            $result['reason']
        );

        $this->assertCount(
            2,
            $result['candidates']
        );

        $this->assertSame(
            [100],
            array_values(
                array_unique(
                    array_column(
                        $result['candidates'],
                        'score'
                    )
                )
            )
        );

        $this->assertEqualsCanonicalizing(
            [
                $firstEntity->uuid,
                $secondEntity->uuid,
            ],
            array_column(
                $result['candidates'],
                'uuid'
            )
        );
    }

    public function test_inactive_duplicate_does_not_block_active_identifier(): void
    {
        [$firstEntity, $identifierType] = $this->foundation(
            isUnique: true
        );

        $secondEntity = $this->createEntity(
            $firstEntity->entity_type_id,
            'Control vigente'
        );

        $this->createIdentifier(
            $firstEntity,
            $identifierType,
            'UNIQUE-OLD',
            false,
            false
        );

        $identifier = $this->createIdentifier(
            $secondEntity,
            $identifierType,
            'unique-old'
        );

        $this->assertTrue($identifier->active);
    }

    /**
     * @return array{Entity, IdentifierType}
     */
    private function foundation(
        bool $isUnique = false
    ): array {
        $entityType = EntityType::query()->create([
            'name' => 'Dispositivo',
            'slug' => 'device',
            'description' => null,
            'active' => true,
        ]);

        $identifierType = IdentifierType::query()->create([
            'name' => 'Código',
            'slug' => 'code',
            'description' => null,
            'is_unique' => $isUnique,
            'active' => true,
        ]);

        return [
            $this->createEntity(
                $entityType->id,
                'Control principal'
            ),
            $identifierType,
        ];
    }

    private function createEntity(
        int $entityTypeId,
        string $name
    ): Entity {
        return Entity::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'entity_type_id' => $entityTypeId,
            'active' => true,
        ]);
    }

    private function createIdentifier(
        Entity $entity,
        IdentifierType $identifierType,
        string $value,
        bool $isPrimary = false,
        bool $active = true
    ): Identifier {
        return Identifier::query()->create([
            'entity_id' => $entity->id,
            'identifier_type_id' => $identifierType->id,
            'value' => $value,
            'is_primary' => $isPrimary,
            'active' => $active,
        ]);
    }
}
