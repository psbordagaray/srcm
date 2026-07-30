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

class EntityDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeFoundationSeeder::class);
    }

    public function test_verified_users_can_read_detail_and_only_managers_see_actions(): void
    {
        $entity = $this->entity();

        $this->get($this->showUrl($entity))
            ->assertRedirect(route('login'));

        $this->actingAs($this->user(UserRole::Viewer, verified: false))
            ->get($this->showUrl($entity))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($entity))
            ->assertOk()
            ->assertSee($entity->name)
            ->assertSee('MAIN-001')
            ->assertDontSee('Agregar identificador')
            ->assertDontSee('Hacer principal');

        $this->actingAs($this->user(UserRole::Operator))
            ->get($this->showUrl($entity))
            ->assertOk()
            ->assertSee('Agregar identificador')
            ->assertSee('Código alternativo');
    }

    public function test_detail_form_lists_only_active_identifier_types(): void
    {
        $entity = $this->entity();

        IdentifierType::query()->create([
            'name' => 'Tipo oculto',
            'slug' => 'hidden-detail-type',
            'description' => null,
            'is_unique' => false,
            'active' => false,
        ]);

        $this->actingAs($this->user(UserRole::Operator))
            ->get($this->showUrl($entity))
            ->assertOk()
            ->assertSee('Código alternativo')
            ->assertDontSee('Tipo oculto');
    }

    public function test_manager_adds_searchable_alternate_identifier(): void
    {
        $entity = $this->entity();
        $alternateType = IdentifierType::query()
            ->where('slug', 'alternate-code')
            ->sole();

        DB::table('audit_logs')->delete();

        $response = $this
            ->actingAs($this->user(UserRole::Operator))
            ->post(
                route('entities.identifiers.store', [
                    'entity' => $entity->uuid,
                ]),
                [
                    'identifier_type_id' => $alternateType->id,
                    'identifier_value' => '  AKB75095308  ',
                ]
            );

        $response
            ->assertRedirect($this->showUrl($entity))
            ->assertSessionHas('success');

        $identifier = Identifier::query()
            ->where('entity_id', $entity->id)
            ->where('normalized_value', 'akb75095308')
            ->sole();

        $this->assertSame('AKB75095308', $identifier->value);
        $this->assertTrue($identifier->active);
        $this->assertFalse($identifier->is_primary);

        $result = app(KnowledgeEngine::class)
            ->resolve('akb75095308');

        $this->assertSame('resolved', $result['status']);
        $this->assertSame($entity->uuid, $result['entity']->uuid);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Identifier::class,
            'auditable_id' => $identifier->id,
            'event' => 'created',
            'route_name' => 'entities.identifiers.store',
        ]);
    }

    public function test_duplicate_identifier_is_rejected_without_audit(): void
    {
        $entity = $this->entity();
        $mainType = IdentifierType::query()
            ->where('slug', 'main-code')
            ->sole();

        DB::table('audit_logs')->delete();

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->from($this->showUrl($entity))
            ->post(
                route('entities.identifiers.store', [
                    'entity' => $entity->uuid,
                ]),
                [
                    'identifier_type_id' => $mainType->id,
                    'identifier_value' => ' main-001 ',
                ]
            )
            ->assertRedirect($this->showUrl($entity))
            ->assertSessionHasErrors('identifier_value');

        $this->assertDatabaseCount('identifiers', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_manager_changes_primary_atomically_and_audits_both_rows(): void
    {
        $entity = $this->entity();
        $alternate = $this->alternateIdentifier(
            $entity,
            'ALT-PRIMARY'
        );

        DB::table('audit_logs')->delete();

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->patch(
                route('entities.identifiers.make-primary', [
                    'entity' => $entity->uuid,
                    'identifier' => $alternate->id,
                ])
            )
            ->assertRedirect($this->showUrl($entity))
            ->assertSessionHas('success');

        $this->assertFalse(
            $entity->identifiers()
                ->where('value', 'MAIN-001')
                ->sole()
                ->is_primary
        );

        $this->assertTrue($alternate->fresh()->is_primary);

        $this->assertSame(
            1,
            $entity->identifiers()
                ->where('active', true)
                ->where('is_primary', true)
                ->count()
        );

        $logs = AuditLog::query()
            ->where('route_name', 'entities.identifiers.make-primary')
            ->where('auditable_type', Identifier::class)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertSame(
            1,
            $logs->pluck('request_id')->unique()->count()
        );
    }

    public function test_inactive_identifier_cannot_become_primary(): void
    {
        $entity = $this->entity();
        $alternate = $this->alternateIdentifier(
            $entity,
            'ALT-INACTIVE',
            active: false
        );

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->from($this->showUrl($entity))
            ->patch(
                route('entities.identifiers.make-primary', [
                    'entity' => $entity->uuid,
                    'identifier' => $alternate->id,
                ])
            )
            ->assertRedirect($this->showUrl($entity))
            ->assertSessionHasErrors('identifier_action');

        $this->assertFalse($alternate->fresh()->is_primary);
    }

    public function test_primary_identifier_cannot_be_deactivated(): void
    {
        $entity = $this->entity();
        $primary = $entity->identifiers()->sole();

        DB::table('audit_logs')->delete();

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->from($this->showUrl($entity))
            ->patch(
                route('entities.identifiers.toggle-active', [
                    'entity' => $entity->uuid,
                    'identifier' => $primary->id,
                ])
            )
            ->assertRedirect($this->showUrl($entity))
            ->assertSessionHasErrors('identifier_action');

        $this->assertTrue($primary->fresh()->active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_primary_identifier_can_be_deactivated_and_reactivated(): void
    {
        $entity = $this->entity();
        $alternate = $this->alternateIdentifier(
            $entity,
            'ALT-TOGGLE'
        );

        $manager = $this->user(UserRole::Operator);

        $this->actingAs($manager)
            ->patch(
                route('entities.identifiers.toggle-active', [
                    'entity' => $entity->uuid,
                    'identifier' => $alternate->id,
                ])
            )
            ->assertRedirect($this->showUrl($entity));

        $this->assertFalse($alternate->fresh()->active);

        $inactiveResult = app(KnowledgeEngine::class)
            ->resolve('ALT-TOGGLE');

        $this->assertSame('not_found', $inactiveResult['status']);

        $this->actingAs($manager)
            ->patch(
                route('entities.identifiers.toggle-active', [
                    'entity' => $entity->uuid,
                    'identifier' => $alternate->id,
                ])
            )
            ->assertRedirect($this->showUrl($entity));

        $this->assertTrue($alternate->fresh()->active);

        $activeResult = app(KnowledgeEngine::class)
            ->resolve('alt-toggle');

        $this->assertSame('resolved', $activeResult['status']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Identifier::class,
            'auditable_id' => $alternate->id,
            'event' => 'deactivated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Identifier::class,
            'auditable_id' => $alternate->id,
            'event' => 'activated',
        ]);
    }

    public function test_identifier_from_another_entity_returns_not_found(): void
    {
        $entity = $this->entity('Entidad uno', 'ENTITY-ONE');
        $other = $this->entity('Entidad dos', 'ENTITY-TWO');
        $foreignIdentifier = $other->identifiers()->sole();

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->patch(
                route('entities.identifiers.toggle-active', [
                    'entity' => $entity->uuid,
                    'identifier' => $foreignIdentifier->id,
                ])
            )
            ->assertNotFound();

        $this->assertTrue($foreignIdentifier->fresh()->active);
    }

    public function test_viewer_cannot_modify_identifiers(): void
    {
        $entity = $this->entity();
        $alternateType = IdentifierType::query()
            ->where('slug', 'alternate-code')
            ->sole();

        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->post(
                route('entities.identifiers.store', [
                    'entity' => $entity->uuid,
                ]),
                [
                    'identifier_type_id' => $alternateType->id,
                    'identifier_value' => 'BLOCKED-ALT',
                ]
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('identifiers', [
            'value' => 'BLOCKED-ALT',
        ]);
    }

    public function test_explorer_exposes_safe_link_to_entity_detail(): void
    {
        $entity = $this->entity();

        $expectedTemplate = json_encode(
            route('entities.show', [
                'entity' => '__ENTITY_UUID__',
            ]),
            JSON_THROW_ON_ERROR
        );

        $this->actingAs($this->user(UserRole::Viewer))
            ->get(route('knowledge.explorer'))
            ->assertOk()
            ->assertSee('Abrir ficha')
            ->assertSee($expectedTemplate, false);

        $this->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($entity))
            ->assertOk()
            ->assertSee('Ficha de conocimiento');
    }

    private function entity(
        string $name = 'Control remoto de prueba',
        string $code = 'MAIN-001'
    ): Entity {
        $entityType = EntityType::query()
            ->where('slug', 'remote-control')
            ->sole();

        $mainType = IdentifierType::query()
            ->where('slug', 'main-code')
            ->sole();

        $entity = Entity::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'entity_type_id' => $entityType->id,
            'active' => true,
        ]);

        $entity->identifiers()->create([
            'identifier_type_id' => $mainType->id,
            'value' => $code,
            'is_primary' => true,
            'active' => true,
        ]);

        return $entity->fresh([
            'entityType',
            'identifiers.identifierType',
        ]);
    }

    private function alternateIdentifier(
        Entity $entity,
        string $value,
        bool $active = true
    ): Identifier {
        $alternateType = IdentifierType::query()
            ->where('slug', 'alternate-code')
            ->sole();

        return $entity->identifiers()->create([
            'identifier_type_id' => $alternateType->id,
            'value' => $value,
            'is_primary' => false,
            'active' => $active,
        ]);
    }

    private function showUrl(Entity $entity): string
    {
        return route('entities.show', [
            'entity' => $entity->uuid,
        ]);
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
