<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\KnowledgeEngine;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Identifier;
use App\Models\IdentifierType;
use App\Models\User;
use Database\Seeders\KnowledgeFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EntityCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeFoundationSeeder::class);
    }

    public function test_foundation_seeder_is_idempotent(): void
    {
        $this->seed(KnowledgeFoundationSeeder::class);

        $this->assertDatabaseCount('entity_types', 5);
        $this->assertDatabaseCount('identifier_types', 8);

        $this->assertDatabaseHas('entity_types', [
            'slug' => 'remote-control',
            'name' => 'Control remoto',
            'active' => true,
        ]);

        $this->assertDatabaseHas('identifier_types', [
            'slug' => 'serial-number',
            'is_unique' => true,
            'active' => true,
        ]);
    }

    public function test_entity_routes_require_verified_catalog_manager(): void
    {
        $this->get(route('entities.create'))
            ->assertRedirect(route('login'));

        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('entities.create'))
            ->assertForbidden();

        $unverifiedOperator = $this->user(
            UserRole::Operator,
            verified: false
        );

        $this->actingAs($unverifiedOperator)
            ->get(route('entities.create'))
            ->assertRedirect(route('verification.notice'));

        foreach ([UserRole::Operator, UserRole::Admin] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('entities.create'))
                ->assertOk();
        }
    }

    public function test_create_form_lists_only_active_types(): void
    {
        EntityType::query()->create([
            'name' => 'Tipo oculto',
            'slug' => 'hidden-entity-type',
            'description' => null,
            'active' => false,
        ]);

        IdentifierType::query()->create([
            'name' => 'Identificador oculto',
            'slug' => 'hidden-identifier-type',
            'description' => null,
            'is_unique' => false,
            'active' => false,
        ]);

        $this->actingAs($this->user(UserRole::Operator))
            ->get(route('entities.create'))
            ->assertOk()
            ->assertSee('Control remoto')
            ->assertSee('Código principal')
            ->assertDontSee('Tipo oculto')
            ->assertDontSee('Identificador oculto');
    }

    public function test_manager_creates_searchable_entity_atomically(): void
    {
        $entityType = EntityType::query()
            ->where('slug', 'remote-control')
            ->sole();

        $identifierType = IdentifierType::query()
            ->where('slug', 'main-code')
            ->sole();

        $response = $this
            ->actingAs($this->user(UserRole::Operator))
            ->post(route('entities.store'), [
                'name' => '  Control remoto Samsung Smart  ',
                'entity_type_id' => $entityType->id,
                'identifier_type_id' => $identifierType->id,
                'identifier_value' => '  AKB75095308  ',
            ]);

        $response
            ->assertRedirect(
                route('knowledge.explorer', [
                    'query' => 'AKB75095308',
                ])
            )
            ->assertSessionHas('success');

        $entity = Entity::query()
            ->where('name', 'Control remoto Samsung Smart')
            ->sole();

        $identifier = Identifier::query()
            ->where('entity_id', $entity->id)
            ->sole();

        $this->assertTrue(Str::isUuid($entity->uuid));
        $this->assertTrue($entity->active);
        $this->assertSame('AKB75095308', $identifier->value);
        $this->assertSame(
            'akb75095308',
            $identifier->normalized_value
        );
        $this->assertTrue($identifier->is_primary);
        $this->assertTrue($identifier->active);

        $result = app(KnowledgeEngine::class)
            ->resolve('akb75095308');

        $this->assertSame('resolved', $result['status']);
        $this->assertSame($entity->uuid, $result['entity']->uuid);
    }

    public function test_unique_duplicate_rolls_back_entity_and_audit(): void
    {
        $entityType = EntityType::query()
            ->where('slug', 'individual-device')
            ->sole();

        $serialType = IdentifierType::query()
            ->where('slug', 'serial-number')
            ->sole();

        $existing = Entity::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Equipo existente',
            'entity_type_id' => $entityType->id,
            'active' => true,
        ]);

        $existing->identifiers()->create([
            'identifier_type_id' => $serialType->id,
            'value' => 'SERIE-UNICA-1',
            'is_primary' => true,
            'active' => true,
        ]);

        $auditCountBeforeDuplicate = AuditLog::query()->count();

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->from(route('entities.create'))
            ->post(route('entities.store'), [
                'name' => 'Equipo que no debe persistir',
                'entity_type_id' => $entityType->id,
                'identifier_type_id' => $serialType->id,
                'identifier_value' => ' serie-unica-1 ',
            ])
            ->assertRedirect(route('entities.create'))
            ->assertSessionHasErrors('identifier_value');

        $this->assertDatabaseCount('entities', 1);
        $this->assertDatabaseCount('identifiers', 1);
        $this->assertDatabaseMissing('entities', [
            'name' => 'Equipo que no debe persistir',
        ]);
        $this->assertSame(
            $auditCountBeforeDuplicate,
            AuditLog::query()->count()
        );
    }

    public function test_inactive_reference_types_are_rejected(): void
    {
        $activeEntityType = EntityType::query()
            ->where('slug', 'remote-control')
            ->sole();

        $activeIdentifierType = IdentifierType::query()
            ->where('slug', 'main-code')
            ->sole();

        $inactiveEntityType = EntityType::query()->create([
            'name' => 'Entidad inactiva',
            'slug' => 'inactive-entity-type',
            'description' => null,
            'active' => false,
        ]);

        $inactiveIdentifierType = IdentifierType::query()->create([
            'name' => 'Identificador inactivo',
            'slug' => 'inactive-identifier-type',
            'description' => null,
            'is_unique' => false,
            'active' => false,
        ]);

        $manager = $this->user(UserRole::Operator);

        $this->actingAs($manager)
            ->post(route('entities.store'), [
                'name' => 'Registro rechazado 1',
                'entity_type_id' => $inactiveEntityType->id,
                'identifier_type_id' => $activeIdentifierType->id,
                'identifier_value' => 'RECHAZADO-1',
            ])
            ->assertSessionHasErrors('entity_type_id');

        $this->actingAs($manager)
            ->post(route('entities.store'), [
                'name' => 'Registro rechazado 2',
                'entity_type_id' => $activeEntityType->id,
                'identifier_type_id' => $inactiveIdentifierType->id,
                'identifier_value' => 'RECHAZADO-2',
            ])
            ->assertSessionHasErrors('identifier_type_id');

        $this->assertDatabaseMissing('entities', [
            'name' => 'Registro rechazado 1',
        ]);
        $this->assertDatabaseMissing('entities', [
            'name' => 'Registro rechazado 2',
        ]);
    }

    public function test_creation_audits_entity_and_identifier(): void
    {
        $entityType = EntityType::query()
            ->where('slug', 'remote-control')
            ->sole();

        $identifierType = IdentifierType::query()
            ->where('slug', 'main-code')
            ->sole();

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('entities.store'), [
                'name' => 'Entidad auditada',
                'entity_type_id' => $entityType->id,
                'identifier_type_id' => $identifierType->id,
                'identifier_value' => 'AUDIT-ENTITY-1',
            ])
            ->assertRedirect();

        $logs = AuditLog::query()
            ->whereIn('auditable_type', [
                Entity::class,
                Identifier::class,
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertSame(
            [Entity::class, Identifier::class],
            $logs->pluck('auditable_type')->all()
        );
        $this->assertSame(
            1,
            $logs->pluck('request_id')->unique()->count()
        );
        $this->assertNotNull($logs->first()->request_id);

        foreach ($logs as $log) {
            $this->assertSame('created', $log->event);
            $this->assertSame('entities.store', $log->route_name);
            $this->assertSame($admin->id, $log->user_id);
            $this->assertSame('admin', $log->actor_role);
        }

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['entity' => 'entity']))
            ->assertOk()
            ->assertSee('Entidad de conocimiento');

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['entity' => 'identifier']))
            ->assertOk()
            ->assertSee('Identificador');
    }

    public function test_explorer_prefills_query_and_hides_creation_from_viewer(): void
    {
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('knowledge.explorer', [
                'query' => 'AKB75095308',
            ]))
            ->assertOk()
            ->assertSee('value="AKB75095308"', false)
            ->assertSee('data-auto-search="true"', false)
            ->assertDontSee('Nueva entidad');

        $this->actingAs($this->user(UserRole::Admin))
            ->get(route('knowledge.explorer'))
            ->assertOk()
            ->assertSee('Nueva entidad')
            ->assertSee(route('entities.create'), false);
    }

    private function user(
        UserRole $role,
        bool $verified = true
    ): User {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
