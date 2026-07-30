<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\KnowledgeEngine;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use App\Models\ProductCategory;
use App\Models\TechnicalModel;
use App\Models\User;
use Database\Seeders\KnowledgeFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechnicalModelKnowledgeBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeFoundationSeeder::class);

        EntityType::query()->firstOrCreate(
            ['slug' => 'televisor'],
            [
                'name' => 'Televisor',
                'description' => 'Equipo receptor de televisión.',
                'active' => true,
            ]
        );

        EntityType::query()->firstOrCreate(
            ['slug' => 'control-remoto'],
            [
                'name' => 'Control remoto legado',
                'description' => 'Tipo compatible con el catálogo existente.',
                'active' => true,
            ]
        );

        IdentifierType::query()->firstOrCreate(
            ['slug' => 'modelo-tecnico'],
            [
                'name' => 'Modelo técnico legado',
                'description' => 'Código técnico existente.',
                'is_unique' => false,
                'active' => true,
            ]
        );

        IdentifierType::query()->firstOrCreate(
            ['slug' => 'model-code'],
            [
                'name' => 'Código de modelo APB',
                'description' => 'Código técnico sincronizado.',
                'is_unique' => false,
                'active' => true,
            ]
        );
    }

    public function test_catalog_creation_builds_searchable_knowledge_and_opens_detail(): void
    {
        [$brand, $category] = $this->catalogFoundation(
            'LG',
            'tv'
        );

        $admin = $this->user(UserRole::Admin);

        $response = $this
            ->actingAs($admin)
            ->post(
                route('technical-models.store'),
                $this->payload(
                    $brand,
                    $category,
                    code: '43LM6300',
                    name: 'TV LG 43LM6300'
                )
            );

        $technicalModel = TechnicalModel::query()
            ->with([
                'knowledgeEntity.entityType',
                'knowledgeIdentifier.identifierType',
            ])
            ->sole();

        $response->assertRedirect(
            route('entities.show', [
                'entity' =>
                    $technicalModel->knowledgeEntity->uuid,
            ])
        );

        $this->assertNotNull(
            $technicalModel->knowledge_entity_id
        );

        $this->assertNotNull(
            $technicalModel->knowledge_identifier_id
        );

        $this->assertSame(
            'televisor',
            $technicalModel
                ->knowledgeEntity
                ->entityType
                ->slug
        );

        $this->assertSame(
            '43LM6300',
            $technicalModel
                ->knowledgeIdentifier
                ->value
        );

        $result = app(KnowledgeEngine::class)
            ->resolve('43LM6300');

        $this->assertTrue($result['resolved']);
        $this->assertSame(
            $technicalModel->knowledgeEntity->uuid,
            $result['entity']->uuid
        );

        $auditableTypes = AuditLog::query()
            ->whereNotNull('request_id')
            ->pluck('auditable_type');

        $this->assertTrue(
            $auditableTypes->contains(
                TechnicalModel::class
            )
        );

        $this->assertTrue(
            $auditableTypes->contains(Entity::class)
        );
    }

    public function test_backfill_adopts_existing_entity_and_preserves_compatibility(): void
    {
        [$brand, $category] = $this->catalogFoundation(
            'LG',
            'tv'
        );

        $television = $this->entity(
            'LG Smart TV 43LM6300',
            'televisor',
            '43LM6300',
            'modelo-tecnico'
        );

        $remote = $this->entity(
            'Control remoto EN2BC27',
            'control-remoto',
            'EN2BC27',
            'model-code'
        );

        $compatibility = Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => min(
                    $remote->id,
                    $television->id
                ),
                'right_entity_id' => max(
                    $remote->id,
                    $television->id
                ),
                'relationship_type' =>
                    'compatible_with',
                'confidence' => 95,
                'source' => 'Relación histórica',
                'evidence' => null,
                'active' => true,
            ])
        );

        $technicalModel = TechnicalModel::withoutEvents(
            fn () => TechnicalModel::query()->create(
                $this->payload(
                    $brand,
                    $category,
                    code: '43LM6300',
                    name: 'TV LG 43LM6300'
                )
            )
        );

        $this->artisan(
            'srcm:bridge-technical-models'
        )->assertSuccessful();

        $technicalModel->refresh();

        $this->assertSame(
            $television->id,
            $technicalModel->knowledge_entity_id
        );

        $this->assertSame(
            $television->identifiers()->sole()->id,
            $technicalModel->knowledge_identifier_id
        );

        $this->assertDatabaseCount('entities', 2);
        $this->assertDatabaseCount(
            'compatibilities',
            1
        );

        $this->assertSame(
            $compatibility->id,
            Compatibility::query()->sole()->id
        );

        $this->artisan(
            'srcm:bridge-technical-models'
        )->assertSuccessful();

        $this->assertDatabaseCount('entities', 2);
        $this->assertDatabaseCount(
            'compatibilities',
            1
        );
    }

    public function test_update_synchronizes_code_name_and_entity_type(): void
    {
        [$brand, $tvCategory] =
            $this->catalogFoundation('LG', 'tv');

        [, $remoteCategory] =
            $this->catalogFoundation(
                'Samsung',
                'control-remoto'
            );

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(
                route('technical-models.store'),
                $this->payload(
                    $brand,
                    $tvCategory,
                    code: '43LM6300',
                    name: 'TV inicial'
                )
            );

        $technicalModel = TechnicalModel::query()
            ->sole();

        $this->actingAs($admin)
            ->put(
                route(
                    'technical-models.update',
                    $technicalModel
                ),
                $this->payload(
                    $brand,
                    $remoteCategory,
                    code: 'AKB75095308',
                    name: 'Control actualizado'
                )
            )
            ->assertRedirect();

        $technicalModel->refresh()->load([
            'knowledgeEntity.entityType',
            'knowledgeIdentifier',
        ]);

        $this->assertSame(
            'Control actualizado',
            $technicalModel
                ->knowledgeEntity
                ->name
        );

        $this->assertSame(
            'control-remoto',
            $technicalModel
                ->knowledgeEntity
                ->entityType
                ->slug
        );

        $this->assertSame(
            'AKB75095308',
            $technicalModel
                ->knowledgeIdentifier
                ->value
        );
    }

    public function test_toggle_active_keeps_catalog_and_knowledge_consistent(): void
    {
        [$brand, $category] =
            $this->catalogFoundation('LG', 'tv');

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(
                route('technical-models.store'),
                $this->payload(
                    $brand,
                    $category,
                    code: '55NANO80'
                )
            );

        $technicalModel = TechnicalModel::query()
            ->with('knowledgeEntity')
            ->sole();

        $this->actingAs($admin)
            ->patch(
                route(
                    'technical-models.toggle-active',
                    $technicalModel
                )
            )
            ->assertRedirect(
                route('technical-models.index')
            );

        $technicalModel->refresh()
            ->load('knowledgeEntity');

        $this->assertFalse($technicalModel->active);
        $this->assertFalse(
            $technicalModel->knowledgeEntity->active
        );

        $result = app(KnowledgeEngine::class)
            ->resolve('55NANO80');

        $this->assertFalse($result['resolved']);
    }

    public function test_show_and_index_open_the_linked_knowledge_record(): void
    {
        [$brand, $category] =
            $this->catalogFoundation('LG', 'tv');

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(
                route('technical-models.store'),
                $this->payload(
                    $brand,
                    $category,
                    code: '50UQ8050'
                )
            );

        $technicalModel = TechnicalModel::query()
            ->with('knowledgeEntity')
            ->sole();

        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(
                route(
                    'technical-models.show',
                    $technicalModel
                )
            )
            ->assertRedirect(
                route('entities.show', [
                    'entity' =>
                        $technicalModel
                            ->knowledgeEntity
                            ->uuid,
                ])
            );

        $this->actingAs($viewer)
            ->get(route('technical-models.index'))
            ->assertOk()
            ->assertSee('Ficha vinculada')
            ->assertSee('Abrir ficha')
            ->assertSee(
                route(
                    'technical-models.show',
                    $technicalModel
                ),
                false
            );
    }

    public function test_backfill_refuses_ambiguous_exact_candidates(): void
    {
        [$brand, $category] =
            $this->catalogFoundation('LG', 'tv');

        $this->entity(
            'TV primera',
            'televisor',
            'DUPLICADO',
            'model-code'
        );

        $this->entity(
            'TV segunda',
            'televisor',
            'DUPLICADO',
            'modelo-tecnico'
        );

        $technicalModel = TechnicalModel::withoutEvents(
            fn () => TechnicalModel::query()->create(
                $this->payload(
                    $brand,
                    $category,
                    code: 'DUPLICADO'
                )
            )
        );

        $this->artisan(
            'srcm:bridge-technical-models'
        )->assertFailed();

        $technicalModel->refresh();

        $this->assertNull(
            $technicalModel->knowledge_entity_id
        );

        $this->assertNull(
            $technicalModel->knowledge_identifier_id
        );
    }

    public function test_sidebar_explains_where_compatibilities_are_managed(): void
    {
        $this
            ->actingAs($this->user(UserRole::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Compatibilidades')
            ->assertSee('Desde fichas');
    }

    /**
     * @return array{0: Brand, 1: ProductCategory}
     */
    private function catalogFoundation(
        string $brandName,
        string $categorySlug
    ): array {
        $brand = Brand::withoutEvents(
            fn () => Brand::query()->firstOrCreate(
                ['slug' => Str::slug($brandName)],
                [
                    'name' => $brandName,
                    'description' => null,
                    'website' => null,
                    'logo' => null,
                    'active' => true,
                ]
            )
        );

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => $categorySlug],
                [
                    'name' => Str::headline(
                        $categorySlug
                    ),
                    'description' => null,
                    'icon' => null,
                    'active' => true,
                ]
            )
        );

        return [$brand, $category];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Brand $brand,
        ProductCategory $category,
        string $code,
        ?string $name = null
    ): array {
        return [
            'brand_id' => $brand->id,
            'product_category_id' =>
                $category->id,
            'code' => $code,
            'name' => $name,
            'description' => null,
            'active' => 1,
        ];
    }

    private function entity(
        string $name,
        string $entityTypeSlug,
        string $code,
        string $identifierTypeSlug
    ): Entity {
        $entityType = EntityType::query()
            ->where('slug', $entityTypeSlug)
            ->sole();

        $identifierType = IdentifierType::query()
            ->where('slug', $identifierTypeSlug)
            ->sole();

        $entity = Entity::withoutEvents(
            fn () => Entity::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'entity_type_id' => $entityType->id,
                'active' => true,
            ])
        );

        $entity->identifiers()->create([
            'identifier_type_id' =>
                $identifierType->id,
            'value' => $code,
            'is_primary' => true,
            'active' => true,
        ]);

        return $entity;
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
