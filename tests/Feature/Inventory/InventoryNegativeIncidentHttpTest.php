<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryNegativeIncidentLifecycle;
use App\Domain\Inventory\InventoryNegativeOverrideIssuer;
use App\Domain\Inventory\InventoryNegativeRequestManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeIncidentStatus;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryNegativeIncidentHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_only_active_administrator_may_open_the_control_screen(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $inactiveAdmin = $this->user($organization, UserRole::Admin);

        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $inactiveAdmin->id)
            ->update(['active' => false]);

        $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index'))
            ->assertOk()
            ->assertSee('Stock negativo')
            ->assertSee(
                route('inventory-negative-incidents.index'),
                false
            );

        foreach ([$operator, $viewer] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->get(route('inventory-negative-incidents.index'))
                ->assertForbidden();

            $this->actingAs($unauthorizedUser)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Stock negativo')
                ->assertDontSee(
                    route('inventory-negative-incidents.index'),
                    false
                );
        }

        $this->actingAs($inactiveAdmin)
            ->get(route('inventory-negative-incidents.index'))
            ->assertForbidden();

        $this->actingAs($inactiveAdmin)
            ->get(route('dashboard'))
            ->assertForbidden();

        $route = app('router')
            ->getRoutes()
            ->getByName('inventory-negative-incidents.index');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains(
            RequireOrganization::class,
            $route->gatherMiddleware()
        );
        $this->assertContains(
            'can:view-inventory-negative-incidents',
            $route->gatherMiddleware()
        );
    }

    public function test_screen_is_scoped_and_shows_exact_incident_dimensions(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant incidente secreto');
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $foreignOperator = $this->user($other, UserRole::Operator);
        $foreignAdmin = $this->user($other, UserRole::Admin);
        $product = $this->product(
            'Aceite con incidencia visible',
            'NEG-UI-OIL',
            3
        );
        $foreignProduct = $this->product(
            'Producto secreto de otro tenant',
            'NEG-UI-SECRET'
        );
        $location = $this->location($organization);
        $foreignLocation = $this->newLocation(
            $other,
            'Depósito negativo secreto'
        );

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::Damaged
        );
        $this->balance(
            $other,
            $foreignProduct,
            $foreignLocation,
            InventoryCondition::New
        );

        $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2.125',
            InventoryCondition::Damaged,
            'negative:http:visible'
        );
        $this->incident(
            $foreignOperator,
            $foreignAdmin,
            $foreignProduct,
            $foreignLocation,
            '99',
            InventoryCondition::New,
            'negative:http:secret'
        );

        $response = $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index'))
            ->assertOk()
            ->assertSee('Aceite con incidencia visible')
            ->assertSee('NEG-UI-OIL')
            ->assertSee('2,125')
            ->assertSee('Dañado o para reparar')
            ->assertSee('Litro')
            ->assertSee($operator->name)
            ->assertSee($admin->name)
            ->assertDontSee('Producto secreto de otro tenant')
            ->assertDontSee('NEG-UI-SECRET')
            ->assertDontSee('Depósito negativo secreto');

        $response->assertViewHas(
            'summary',
            fn (array $summary): bool =>
                $summary['pendingRequests'] === 0
                && $summary['activeOverrides'] === 0
                && $summary['activeIncidents'] === 1
                && $summary['pendingLines'] === 1
        );
    }

    public function test_summary_reports_each_stage_without_mixing_quantities(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $location = $this->location($organization);
        $pendingProduct = $this->product(
            'Solicitud pendiente UI',
            'NEG-UI-PENDING'
        );
        $overrideProduct = $this->product(
            'Override activo UI',
            'NEG-UI-OVERRIDE'
        );
        $incidentProduct = $this->product(
            'Incidencia activa UI',
            'NEG-UI-INCIDENT'
        );

        foreach (
            [$pendingProduct, $overrideProduct, $incidentProduct]
            as $product
        ) {
            $this->balance(
                $organization,
                $product,
                $location,
                InventoryCondition::New
            );
        }

        $pendingMovement = $this->movement(
            $operator,
            $pendingProduct,
            $location,
            '1',
            InventoryCondition::New,
            'negative:http:pending'
        );
        app(InventoryNegativeRequestManager::class)->request(
            $pendingMovement,
            'Solicitud pendiente para panel',
            $operator
        );

        $overrideMovement = $this->movement(
            $operator,
            $overrideProduct,
            $location,
            '1',
            InventoryCondition::New,
            'negative:http:override'
        );
        $overrideRequest = app(
            InventoryNegativeRequestManager::class
        )->request(
            $overrideMovement,
            'Solicitud autorizada para panel',
            $operator
        );
        app(InventoryNegativeOverrideIssuer::class)->issue(
            $overrideRequest,
            $admin
        );

        $this->incident(
            $operator,
            $admin,
            $incidentProduct,
            $location,
            '1',
            InventoryCondition::New,
            'negative:http:incident'
        );

        $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index'))
            ->assertOk()
            ->assertViewHas(
                'summary',
                fn (array $summary): bool =>
                    $summary['pendingRequests'] === 1
                    && $summary['activeOverrides'] === 1
                    && $summary['activeIncidents'] === 1
                    && $summary['pendingLines'] === 1
            );
    }

    public function test_filters_combine_and_unknown_values_fail_closed(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $firstLocation = $this->location($organization);
        $secondLocation = $this->newLocation(
            $organization,
            'Sector negativo usado'
        );
        $firstProduct = $this->product(
            'Incidencia nueva visible',
            'NEG-UI-NEW'
        );
        $secondProduct = $this->product(
            'Incidencia usada buscada',
            'NEG-UI-USED'
        );

        $this->balance(
            $organization,
            $firstProduct,
            $firstLocation,
            InventoryCondition::New
        );
        $this->balance(
            $organization,
            $secondProduct,
            $secondLocation,
            InventoryCondition::Used
        );

        $first = $this->incident(
            $operator,
            $admin,
            $firstProduct,
            $firstLocation,
            '2',
            InventoryCondition::New,
            'negative:http:filter:new'
        );
        $second = $this->incident(
            $operator,
            $admin,
            $secondProduct,
            $secondLocation,
            '3',
            InventoryCondition::Used,
            'negative:http:filter:used'
        );
        app(InventoryNegativeIncidentLifecycle::class)->markUnderReview(
            $second,
            'Revisión administrativa de filtro',
            $admin
        );

        $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index', [
                'search' => 'usada buscada',
                'status' =>
                    InventoryNegativeIncidentStatus::UnderReview->value,
                'attention' => 'pending',
                'location' => $secondLocation->id,
                'condition' => InventoryCondition::Used->value,
            ]))
            ->assertOk()
            ->assertSee('Incidencia usada buscada')
            ->assertSee('NEG-UI-USED')
            ->assertSee('En revisión')
            ->assertDontSee('Incidencia nueva visible')
            ->assertDontSee('NEG-UI-NEW');

        $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index', [
                'status' => 'inventado',
                'attention' => 'inyeccion',
                'condition' => 'otra',
                'location' => '../../tenant',
            ]))
            ->assertOk()
            ->assertSee('Incidencia nueva visible')
            ->assertSee('Incidencia usada buscada')
            ->assertDontSee('value="inventado" selected', false)
            ->assertDontSee('value="inyeccion" selected', false)
            ->assertDontSee('value="otra" selected', false);

        $this->assertSame(
            InventoryNegativeIncidentStatus::Open,
            $first->refresh()->status
        );
    }

    public function test_admin_may_mark_an_incident_under_review_with_attribution(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product(
            'Incidencia para revisión HTTP',
            'NEG-UI-REVIEW'
        );
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2',
            InventoryCondition::New,
            'negative:http:review'
        );

        $this->actingAs($admin)
            ->patch($this->reviewRoute($incident), [
                'reason' => '  Control   documental iniciado  ',
            ])
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                'La incidencia quedó marcada en revisión.'
            );

        $incident->refresh();

        $this->assertSame(
            InventoryNegativeIncidentStatus::UnderReview,
            $incident->status
        );
        $this->assertSame($admin->id, $incident->reviewed_by_user_id);
        $this->assertNotNull($incident->reviewed_at);
        $this->assertSame(
            'Control documental iniciado',
            $incident->review_reason
        );
        $this->assertDatabaseHas(
            'inventory_negative_incident_status_histories',
            [
                'organization_id' => $organization->id,
                'inventory_negative_incident_id' => $incident->id,
                'from_status' =>
                    InventoryNegativeIncidentStatus::Open->value,
                'to_status' =>
                    InventoryNegativeIncidentStatus::UnderReview->value,
                'changed_by_user_id' => $admin->id,
                'reason' => 'Control documental iniciado',
            ]
        );

        $this->actingAs($admin)
            ->get(route('inventory-negative-incidents.index'))
            ->assertOk()
            ->assertSee('En revisión')
            ->assertSee('Control documental iniciado')
            ->assertSee($admin->name);
    }

    public function test_resolution_rejects_invalid_reason_and_pending_deficit(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product(
            'Incidencia todavía pendiente',
            'NEG-UI-PENDING-CLOSE'
        );
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '3',
            InventoryCondition::New,
            'negative:http:pending-close'
        );

        $this->actingAs($admin)
            ->patch($this->resolveRoute($incident), ['reason' => 'breve'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->patch($this->resolveRoute($incident), [
                'reason' => 'Intento de cierre aún no regularizado',
            ])
            ->assertRedirect()
            ->assertSessionHas(
                'error',
                'La incidencia debe estar físicamente regularizada antes de resolverse.'
            );

        $incident->refresh();

        $this->assertSame(
            InventoryNegativeIncidentStatus::Open,
            $incident->status
        );
        $this->assertNull($incident->resolved_by_user_id);
        $this->assertNull($incident->resolved_at);
        $this->assertNull($incident->resolution_reason);
        $this->assertSame(
            1,
            $incident->statusHistory()->count()
        );
    }

    public function test_regularized_incident_may_be_resolved_once(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product(
            'Incidencia regularizada para cierre',
            'NEG-UI-CLOSE'
        );
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2',
            InventoryCondition::New,
            'negative:http:close'
        );

        $this->actingAs($admin)
            ->patch($this->reviewRoute($incident), [
                'reason' => 'Revisión previa al ingreso regularizador',
            ])
            ->assertSessionHas('success');

        $receipt = $this->movement(
            $operator,
            $product,
            $location,
            '2',
            InventoryCondition::New,
            'negative:http:close:receipt',
            InventoryMovementType::Receipt
        );
        app(InventoryMovementConfirmer::class)->confirm(
            $receipt,
            $operator
        );

        $incident->refresh();
        $this->assertNotNull($incident->regularized_at);
        $this->assertSame(
            '0.000000',
            $incident->lines()->firstOrFail()->pending_deficit
        );

        $payload = [
            'reason' => 'Saldo repuesto y documentación controlada',
        ];

        $this->actingAs($admin)
            ->patch($this->resolveRoute($incident), $payload)
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                'La incidencia quedó resuelta administrativamente.'
            );

        $incident->refresh();
        $this->assertSame(
            InventoryNegativeIncidentStatus::Resolved,
            $incident->status
        );
        $this->assertSame($admin->id, $incident->resolved_by_user_id);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(
            $payload['reason'],
            $incident->resolution_reason
        );

        $historyCount = $incident->statusHistory()->count();

        $this->actingAs($admin)
            ->patch($this->resolveRoute($incident), $payload)
            ->assertSessionHas('success');

        $this->assertSame(
            $historyCount,
            $incident->statusHistory()->count()
        );
        $this->assertDatabaseHas(
            'inventory_negative_incident_status_histories',
            [
                'inventory_negative_incident_id' => $incident->id,
                'from_status' =>
                    InventoryNegativeIncidentStatus::UnderReview->value,
                'to_status' =>
                    InventoryNegativeIncidentStatus::Resolved->value,
                'changed_by_user_id' => $admin->id,
                'reason' => $payload['reason'],
            ]
        );
    }

    public function test_transition_routes_reject_non_admin_and_foreign_tenant(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant ajeno al cierre');
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $foreignAdmin = $this->user($other, UserRole::Admin);
        $product = $this->product(
            'Incidencia protegida por tenant',
            'NEG-UI-SCOPED-ACTION'
        );
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '1',
            InventoryCondition::New,
            'negative:http:scoped-action'
        );
        $payload = ['reason' => 'Control de acceso administrativo'];

        $this->actingAs($operator)
            ->patch($this->reviewRoute($incident), $payload)
            ->assertForbidden();

        $this->actingAs($foreignAdmin)
            ->patch($this->reviewRoute($incident), $payload)
            ->assertNotFound();

        $this->assertSame(
            InventoryNegativeIncidentStatus::Open,
            $incident->refresh()->status
        );

        foreach (['review', 'resolve'] as $action) {
            $route = app('router')->getRoutes()->getByName(
                'inventory-negative-incidents.'.$action
            );

            $this->assertNotNull($route);
            $this->assertSame(['PATCH'], $route->methods());
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains(
                'can:review-inventory-negative-incidents',
                $route->gatherMiddleware()
            );
        }
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                ['role' => $role->value, 'active' => true]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function newLocation(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );
    }

    private function product(
        string $name,
        string $sku,
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'negative-incident-http'],
                ['name' => 'Negative Incident HTTP', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $scale > 0 ? 'l' : 'unit',
                'quantity_scale' => $scale,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        InventoryCondition $condition
    ): InventoryBalance {
        return InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => $condition,
            'quantity' => '0',
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);
    }

    private function incident(
        User $operator,
        User $admin,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        InventoryCondition $condition,
        string $key
    ): InventoryNegativeIncident {
        $movement = $this->movement(
            $operator,
            $product,
            $location,
            $quantity,
            $condition,
            $key
        );
        $request = app(InventoryNegativeRequestManager::class)->request(
            $movement,
            'Solicitud excepcional para panel de incidencias',
            $operator
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($request, $admin)
            ->override;

        return app(InventoryMovementConfirmer::class)
            ->confirmWithNegativeOverride(
                $movement,
                $override,
                $operator
            )->incident;
    }

    private function movement(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        InventoryCondition $condition,
        string $key,
        InventoryMovementType $type = InventoryMovementType::Issue
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: $type,
                effectiveAt: now(),
                reason: $type === InventoryMovementType::Receipt
                    ? 'Ingreso para regularización de incidencia'
                    : 'Salida excepcional para panel de incidencias',
                idempotencyKey: $key,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: $condition,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    sourceLocationId:
                        $type === InventoryMovementType::Receipt
                            ? null
                            : $location->id,
                    destinationLocationId:
                        $type === InventoryMovementType::Receipt
                            ? $location->id
                            : null
                )]
            ),
            $actor
        );
    }

    private function reviewRoute(
        InventoryNegativeIncident $incident
    ): string {
        return route('inventory-negative-incidents.review', [
            'inventoryNegativeIncident' => $incident->public_id,
        ]);
    }

    private function resolveRoute(
        InventoryNegativeIncident $incident
    ): string {
        return route('inventory-negative-incidents.resolve', [
            'inventoryNegativeIncident' => $incident->public_id,
        ]);
    }
}
