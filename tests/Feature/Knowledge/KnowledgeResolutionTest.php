<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\KnowledgeEngine;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_endpoint_redirects_guests_to_login(): void
    {
        $response = $this->get(
            route('knowledge.show', ['query' => 'EN2BC27'])
        );

        $response->assertRedirect(route('login'));
    }

    public function test_empty_query_returns_not_found(): void
    {
        $result = app(KnowledgeEngine::class)->resolve('   ');

        $this->assertSame('not_found', $result['status']);
        $this->assertFalse($result['resolved']);
        $this->assertSame('', $result['query']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_exact_identifier_resolves_case_insensitively(): void
    {
        [$entity] = $this->createEntityWithIdentifier(
            'EN2BC27',
            'Control remoto EN2BC27'
        );

        $result = app(KnowledgeEngine::class)->resolve('en2bc27');

        $this->assertSame('resolved', $result['status']);
        $this->assertTrue($result['resolved']);
        $this->assertSame('exact', $result['match_type']);
        $this->assertSame($entity->uuid, $result['entity']->uuid);
    }

    public function test_exact_uuid_resolves_entity(): void
    {
        [$entity] = $this->createEntityWithIdentifier(
            'AKB75095308',
            'Control remoto LG'
        );

        $result = app(KnowledgeEngine::class)->resolve($entity->uuid);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame($entity->uuid, $result['entity']->uuid);
    }

    public function test_partial_identifier_returns_candidates_without_auto_opening(): void
    {
        [$entity] = $this->createEntityWithIdentifier(
            'EN2BC27',
            'Control remoto EN2BC27'
        );

        $result = app(KnowledgeEngine::class)->resolve('EN2');

        $this->assertSame('candidates', $result['status']);
        $this->assertFalse($result['resolved']);
        $this->assertCount(1, $result['candidates']);

        $candidate = $result['candidates'][0];

        $this->assertSame($entity->uuid, $candidate['uuid']);
        $this->assertSame('identifier_starts_with', $candidate['matched_by']);
        $this->assertSame('EN2BC27', $candidate['matched_value']);
        $this->assertSame(90, $candidate['score']);
    }

    public function test_unknown_query_returns_not_found(): void
    {
        $this->createEntityWithIdentifier(
            'EN2BC27',
            'Control remoto EN2BC27'
        );

        $result = app(KnowledgeEngine::class)->resolve('NO-EXISTE-999');

        $this->assertSame('not_found', $result['status']);
        $this->assertFalse($result['resolved']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_resolved_entities_expose_both_compatibility_directions(): void
    {
        [$remote] = $this->createEntityWithIdentifier(
            'EN2BC27',
            'Control remoto EN2BC27'
        );

        [$television] = $this->createEntityWithIdentifier(
            '43LM6300',
            'Televisor LG 43LM6300'
        );

        Compatibility::create([
            'left_entity_id' => $remote->id,
            'right_entity_id' => $television->id,
            'relationship_type' => 'compatible_with',
            'confidence' => 95,
            'source' => 'Prueba de mostrador',
            'evidence' => 'Encendido, volumen y navegación verificados.',
            'active' => true,
        ]);

        $remoteResult = app(KnowledgeEngine::class)->resolve('EN2BC27');
        $televisionResult = app(KnowledgeEngine::class)->resolve('43LM6300');

        $this->assertCount(
            1,
            $remoteResult['compatibilities']['outgoing']
        );

        $this->assertCount(
            1,
            $televisionResult['compatibilities']['incoming']
        );

        $this->assertSame(
            $television->uuid,
            $remoteResult['compatibilities']['outgoing']
                ->first()
                ->rightEntity
                ->uuid
        );

        $this->assertSame(
            $remote->uuid,
            $televisionResult['compatibilities']['incoming']
                ->first()
                ->leftEntity
                ->uuid
        );
    }

    public function test_authenticated_user_can_use_knowledge_endpoint(): void
    {
        [$entity] = $this->createEntityWithIdentifier(
            'EN2BC27',
            'Control remoto EN2BC27'
        );

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson(
                route('knowledge.show', ['query' => 'EN2BC27'])
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('entity.uuid', $entity->uuid)
            ->assertJsonPath(
                'entity.name',
                'Control remoto EN2BC27'
            );
    }

    private function createEntityWithIdentifier(
        string $identifierValue,
        string $name
    ): array {
        $entityType = EntityType::firstOrCreate(
            ['slug' => 'product'],
            [
                'name' => 'Producto',
                'description' => 'Producto identificable por SRCM.',
                'active' => true,
            ]
        );

        $identifierType = IdentifierType::firstOrCreate(
            ['slug' => 'code'],
            [
                'name' => 'Código',
                'description' => 'Código comercial o técnico.',
                'is_unique' => false,
                'active' => true,
            ]
        );

        $entity = Entity::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'entity_type_id' => $entityType->id,
            'active' => true,
        ]);

        $identifier = Identifier::create([
            'entity_id' => $entity->id,
            'identifier_type_id' => $identifierType->id,
            'value' => $identifierValue,
            'is_primary' => true,
            'active' => true,
        ]);

        return [$entity, $identifier];
    }
}