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
        string $key
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Issue,
                effectiveAt: now(),
                reason: 'Salida excepcional para panel de incidencias',
                idempotencyKey: $key,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: $condition,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    sourceLocationId: $location->id,
                    destinationLocationId: null
                )]
            ),
            $actor
        );
    }
}
