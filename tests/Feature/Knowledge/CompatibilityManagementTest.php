<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\KnowledgeEngine;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\User;
use Database\Seeders\KnowledgeFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompatibilityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeFoundationSeeder::class);
    }

    public function test_verified_users_read_relations_and_only_managers_see_actions(): void
    {
        $remote = $this->entity(
            'Control remoto',
            'remote-control'
        );
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );

        $compatibility = Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => $remote->id,
                'right_entity_id' => $television->id,
                'relationship_type' => 'compatible_with',
                'confidence' => 95,
                'source' => 'Prueba local',
                'evidence' => 'Encendido y volumen verificados.',
                'active' => true,
            ])
        );

        $viewerResponse = $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($remote))
            ->assertOk()
            ->assertSee('Televisor')
            ->assertSee('Compatible con')
            ->assertDontSee('Agregar relación');

        $viewerResponse->assertDontSee(
            route('entities.compatibilities.toggle-active', [
                'entity' => $remote->uuid,
                'compatibility' => $compatibility->id,
            ]),
            false
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->get($this->showUrl($remote))
            ->assertOk()
            ->assertSee('Agregar relación')
            ->assertSee('Guardar compatibilidad')
            ->assertSee(
                route('entities.compatibilities.toggle-active', [
                    'entity' => $remote->uuid,
                    'compatibility' => $compatibility->id,
                ]),
                false
            );
    }

    public function test_manager_creates_symmetric_compatibility_visible_from_both_sides(): void
    {
        $television = $this->entity(
            'TV Samsung 43LM6300',
            'technical-model'
        );
        $remote = $this->entity(
            'Control AKB75095308',
            'remote-control'
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $remote->uuid,
                ]),
                [
                    'related_entity_uuid' => $television->uuid,
                    'relationship_type' => 'compatible_with',
                    'confidence' => 96,
                    'source' => 'Prueba de mostrador',
                    'evidence' =>
                        'Encendido, volumen y navegación confirmados.',
                ]
            )
            ->assertRedirect($this->showUrl($remote));

        $compatibility = Compatibility::query()->sole();

        $this->assertSame(
            min($remote->id, $television->id),
            $compatibility->left_entity_id
        );

        $this->assertSame(
            max($remote->id, $television->id),
            $compatibility->right_entity_id
        );

        $this->assertSame(96, $compatibility->confidence);
        $this->assertTrue($compatibility->active);

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($remote))
            ->assertOk()
            ->assertSee('TV Samsung 43LM6300')
            ->assertSee('Compatible con');

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($television))
            ->assertOk()
            ->assertSee('Control AKB75095308')
            ->assertSee('Compatible con');

        $remoteResult = app(KnowledgeEngine::class)
            ->resolve($remote->uuid);

        $televisionResult = app(KnowledgeEngine::class)
            ->resolve($television->uuid);

        $this->assertCount(
            1,
            array_merge(
                $remoteResult['compatibilities']['outgoing']
                    ->all(),
                $remoteResult['compatibilities']['incoming']
                    ->all()
            )
        );

        $this->assertCount(
            1,
            array_merge(
                $televisionResult['compatibilities']['outgoing']
                    ->all(),
                $televisionResult['compatibilities']['incoming']
                    ->all()
            )
        );
    }

    public function test_entity_cannot_be_related_to_itself(): void
    {
        $entity = $this->entity();

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $entity->uuid,
                ]),
                $this->payload($entity)
            )
            ->assertSessionHasErrors('related_entity_uuid');

        $this->assertDatabaseCount('compatibilities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reverse_symmetric_duplicate_is_rejected(): void
    {
        $remote = $this->entity('Control remoto');
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $remote->uuid,
                ]),
                $this->payload($television)
            )
            ->assertRedirect($this->showUrl($remote));

        $this->actingAs($admin)
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $television->uuid,
                ]),
                $this->payload($remote)
            )
            ->assertSessionHasErrors('related_entity_uuid');

        $this->assertDatabaseCount('compatibilities', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_directional_relationship_preserves_direction_and_labels(): void
    {
        $replacement = $this->entity(
            'Control reemplazo'
        );
        $original = $this->entity(
            'Control original'
        );

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $replacement->uuid,
                ]),
                [
                    ...$this->payload($original),
                    'relationship_type' => 'replaces',
                ]
            )
            ->assertRedirect($this->showUrl($replacement));

        $compatibility = Compatibility::query()->sole();

        $this->assertSame(
            $replacement->id,
            $compatibility->left_entity_id
        );

        $this->assertSame(
            $original->id,
            $compatibility->right_entity_id
        );

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($replacement))
            ->assertSee('Reemplaza a');

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($original))
            ->assertSee('Reemplazado por');
    }

    public function test_inactive_related_entity_is_rejected(): void
    {
        $entity = $this->entity();
        $inactive = $this->entity('Entidad inactiva');

        Entity::withoutEvents(
            fn () => $inactive->update([
                'active' => false,
            ])
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $entity->uuid,
                ]),
                $this->payload($inactive)
            )
            ->assertSessionHasErrors('related_entity_uuid');

        $this->assertDatabaseCount('compatibilities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_relation_can_be_deactivated_and_reactivated_from_either_side(): void
    {
        $remote = $this->entity('Control');
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );

        $compatibility = Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => $remote->id,
                'right_entity_id' => $television->id,
                'relationship_type' => 'compatible_with',
                'confidence' => 90,
                'source' => null,
                'evidence' => null,
                'active' => true,
            ])
        );

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->patch(
                route(
                    'entities.compatibilities.toggle-active',
                    [
                        'entity' => $remote->uuid,
                        'compatibility' => $compatibility->id,
                    ]
                )
            )
            ->assertRedirect($this->showUrl($remote));

        $this->assertFalse(
            $compatibility->fresh()->active
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Compatibility::class,
            'auditable_id' => (string) $compatibility->id,
            'event' => 'deactivated',
        ]);

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($remote))
            ->assertDontSee('Televisor');

        $this->actingAs($admin)
            ->get($this->showUrl($remote))
            ->assertSee('Televisor')
            ->assertSee('Inactiva');

        $this->actingAs($admin)
            ->patch(
                route(
                    'entities.compatibilities.toggle-active',
                    [
                        'entity' => $television->uuid,
                        'compatibility' => $compatibility->id,
                    ]
                )
            )
            ->assertRedirect($this->showUrl($television));

        $this->assertTrue(
            $compatibility->fresh()->active
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Compatibility::class,
            'auditable_id' => (string) $compatibility->id,
            'event' => 'activated',
        ]);
    }

    public function test_inactive_relation_is_restored_without_duplicate_row(): void
    {
        $remote = $this->entity('Control');
        $television = $this->entity(
            'Televisor',
            'technical-model'
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
                'relationship_type' => 'compatible_with',
                'confidence' => 60,
                'source' => 'Fuente anterior',
                'evidence' => null,
                'active' => false,
            ])
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $television->uuid,
                ]),
                [
                    ...$this->payload($remote),
                    'confidence' => 99,
                    'source' => 'Nueva verificación',
                ]
            )
            ->assertRedirect($this->showUrl($television));

        $this->assertDatabaseCount('compatibilities', 1);

        $compatibility->refresh();

        $this->assertTrue($compatibility->active);
        $this->assertSame(99, $compatibility->confidence);
        $this->assertSame(
            'Nueva verificación',
            $compatibility->source
        );
    }

    public function test_relation_from_unrelated_entity_returns_not_found(): void
    {
        $first = $this->entity('Primera');
        $second = $this->entity('Segunda');
        $foreign = $this->entity('Ajena');

        $compatibility = Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => $first->id,
                'right_entity_id' => $second->id,
                'relationship_type' => 'compatible_with',
                'confidence' => 80,
                'active' => true,
            ])
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->patch(
                route(
                    'entities.compatibilities.toggle-active',
                    [
                        'entity' => $foreign->uuid,
                        'compatibility' => $compatibility->id,
                    ]
                )
            )
            ->assertNotFound();

        $this->assertTrue(
            $compatibility->fresh()->active
        );
    }

    public function test_viewer_cannot_create_or_toggle_relations(): void
    {
        $remote = $this->entity('Control');
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );

        $compatibility = Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => $remote->id,
                'right_entity_id' => $television->id,
                'relationship_type' => 'compatible_with',
                'confidence' => 80,
                'active' => true,
            ])
        );

        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $remote->uuid,
                ]),
                $this->payload($television)
            )
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(
                route(
                    'entities.compatibilities.toggle-active',
                    [
                        'entity' => $remote->uuid,
                        'compatibility' => $compatibility->id,
                    ]
                )
            )
            ->assertForbidden();

        $this->assertTrue(
            $compatibility->fresh()->active
        );

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_form_exposes_safe_search_and_fixed_relation_types(): void
    {
        $entity = $this->entity();

        $expectedTemplate = json_encode(
            route('knowledge.show', [
                'query' => '__QUERY__',
            ]),
            JSON_THROW_ON_ERROR
        );

        $response = $this
            ->actingAs($this->user(UserRole::Admin))
            ->get($this->showUrl($entity))
            ->assertOk()
            ->assertSee('Buscar entidad relacionada')
            ->assertSee('Guardar compatibilidad')
            ->assertSee($expectedTemplate, false);

        foreach ([
            'compatible_with',
            'replaces',
            'component_of',
            'accessory_for',
        ] as $type) {
            $response->assertSee(
                'value="'.$type.'"',
                false
            );
        }

        $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get($this->showUrl($entity))
            ->assertDontSee('Guardar compatibilidad');
    }

    public function test_creation_and_toggle_are_audited_and_filterable(): void
    {
        $remote = $this->entity('Control');
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $remote->uuid,
                ]),
                $this->payload($television)
            )
            ->assertRedirect($this->showUrl($remote));

        $compatibility = Compatibility::query()->sole();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Compatibility::class,
            'auditable_id' => (string) $compatibility->id,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(
                route(
                    'entities.compatibilities.toggle-active',
                    [
                        'entity' => $remote->uuid,
                        'compatibility' => $compatibility->id,
                    ]
                )
            )
            ->assertRedirect($this->showUrl($remote));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Compatibility::class,
            'auditable_id' => (string) $compatibility->id,
            'event' => 'deactivated',
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', [
                'entity' => 'compatibility',
            ]))
            ->assertOk()
            ->assertSee('Compatibilidad');
    }

    public function test_confidence_outside_allowed_range_is_rejected(): void
    {
        $remote = $this->entity('Control');
        $television = $this->entity(
            'Televisor',
            'technical-model'
        );

        $this
            ->actingAs($this->user(UserRole::Admin))
            ->post(
                route('entities.compatibilities.store', [
                    'entity' => $remote->uuid,
                ]),
                [
                    ...$this->payload($television),
                    'confidence' => 101,
                ]
            )
            ->assertSessionHasErrors('confidence');

        $this->assertDatabaseCount('compatibilities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return array{
     *     related_entity_uuid: string,
     *     relationship_type: string,
     *     confidence: int,
     *     source: string,
     *     evidence: string
     * }
     */
    private function payload(Entity $related): array
    {
        return [
            'related_entity_uuid' => $related->uuid,
            'relationship_type' => 'compatible_with',
            'confidence' => 90,
            'source' => 'Prueba automatizada',
            'evidence' => 'Relación verificada por el contrato de pruebas.',
        ];
    }

    private function entity(
        string $name = 'Entidad de prueba',
        string $typeSlug = 'remote-control'
    ): Entity {
        $entityType = EntityType::query()
            ->where('slug', $typeSlug)
            ->sole();

        return Entity::withoutEvents(
            fn () => Entity::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'entity_type_id' => $entityType->id,
                'active' => true,
            ])
        );
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function showUrl(Entity $entity): string
    {
        return route('entities.show', [
            'entity' => $entity->uuid,
        ]);
    }
}
