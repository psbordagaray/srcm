<?php

namespace Tests\Feature\Offline;

use App\Domain\Commerce\CommerceSalePolicyGuard;
use App\Domain\Device\OperationalDeviceBrowserBindingManager;
use App\Domain\Device\OperationalDeviceRegistry;
use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Enums\InventoryCondition;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalDeviceReadModelSnapshotFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_snapshot_requires_session_human_permission_binding_and_read_model_capability(): void
    {
        $this
            ->getJson(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertRedirect(route('login'));

        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );

        $this->actingAs($admin);

        $noCapability = app(
            OperationalDeviceRegistry::class
        )->register(
            $admin,
            'POS sin read model',
            []
        );

        $noCapabilityIssue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $noCapability);

        $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $noCapabilityIssue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertForbidden();

        $device = app(
            OperationalDeviceRegistry::class
        )->register(
            $admin,
            'POS read model',
            [
                OperationalDeviceCapability::RestrictedOfflineReadModel,
            ]
        );

        $issue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $issue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertOk();

        $this->actingAs($viewer);

        $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $issue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertForbidden();
    }

    public function test_snapshot_exposes_minimum_versioned_read_model_and_no_sensitive_transaction_surfaces(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-28 20:30:00')
        );

        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->active()
            ->orderBy('id')
            ->firstOrFail();

        $product = $this->product(
            'Cable HDMI 2.1',
            'HDMI-21'
        );

        OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => 125000,
            'valid_from' => CarbonImmutable::now()->subHour(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Precio para snapshot.',
            'created_by_user_id' => $admin->id,
        ]);

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '4.000000',
            'base_unit_code' => $product->base_unit_code,
            'version' => 7,
        ]);

        $device = app(
            OperationalDeviceRegistry::class
        )->register(
            $admin,
            'POS snapshot',
            [
                OperationalDeviceCapability::RestrictedOfflineReadModel,
            ]
        );

        $issue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $response = $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $issue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            );

        $response
            ->assertOk()
            ->assertJsonPath('snapshot_version', 2)
            ->assertJsonPath(
                'generated_at',
                CarbonImmutable::now()->toAtomString()
            )
            ->assertJsonPath(
                'scope.binding_public_id',
                $issue->binding->public_id
            )
            ->assertJsonPath(
                'scope.device_public_id',
                $device->public_id
            )
            ->assertJsonPath(
                'scope.binding_expires_at',
                $issue->binding->expires_at->toAtomString()
            )
            ->assertJsonPath(
                'device.public_id',
                $device->public_id
            )
            ->assertJsonPath(
                'policy.server_authoritative_at_confirmation',
                true
            )
            ->assertJsonPath(
                'policy.offline_final_sale_allowed',
                false
            )
            ->assertJsonPath(
                'policy.offline_payment_finalization_allowed',
                false
            )
            ->assertJsonPath(
                'policy.offline_fiscal_authorization_allowed',
                false
            )
            ->assertJsonPath(
                'policy.silent_price_or_stock_conflict_merge_allowed',
                false
            )
            ->assertJsonFragment([
                'sku' => 'HDMI-21',
                'name' => 'Cable HDMI 2.1',
            ])
            ->assertJsonFragment([
                'product_id' => $product->id,
                'currency' => 'ARS',
                'amount_minor' => 125000,
            ])
            ->assertJsonFragment([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'available_quantity' => '4.000000',
                'balance_version' => 7,
            ]);

        $fingerprint = (string) $response->json(
            'content_fingerprint'
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $fingerprint
        );

        $content = $response->getContent();

        foreach ([
            '"customers"',
            '"customer_credit"',
            '"cash_session"',
            '"financial_accounts"',
            '"payments"',
            '"fiscal_credentials"',
            '"token_hash"',
            '"token"',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $content
            );
        }

        $this->assertSame(
            'no-store, private',
            $response->headers->get('Cache-Control')
        );
        $this->assertSame(
            'Cookie',
            $response->headers->get('Vary')
        );

        CarbonImmutable::setTestNow();
    }

    public function test_content_fingerprint_is_stable_without_data_change_and_changes_for_price_or_balance_version(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-28 21:00:00')
        );

        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->active()
            ->orderBy('id')
            ->firstOrFail();

        $product = $this->product(
            'Adaptador USB-C',
            'USBC-01'
        );

        $price = OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => 200000,
            'valid_from' => CarbonImmutable::now()->subHour(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Fingerprint.',
            'created_by_user_id' => $admin->id,
        ]);

        $balance = InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '2.000000',
            'base_unit_code' => $product->base_unit_code,
            'version' => 3,
        ]);

        $device = app(
            OperationalDeviceRegistry::class
        )->register(
            $admin,
            'POS fingerprint',
            [
                OperationalDeviceCapability::RestrictedOfflineReadModel,
            ]
        );

        $issue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $first = $this->snapshotFingerprint(
            $issue->token
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addMinute()
        );

        $second = $this->snapshotFingerprint(
            $issue->token
        );

        $this->assertSame($first, $second);

        DB::table('organization_product_prices')
            ->where('id', $price->id)
            ->update(['amount_minor' => 210000]);

        $third = $this->snapshotFingerprint(
            $issue->token
        );

        $this->assertNotSame($second, $third);

        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update([
                'quantity' => '1.000000',
                'version' => 4,
            ]);

        $fourth = $this->snapshotFingerprint(
            $issue->token
        );

        $this->assertNotSame($third, $fourth);

        CarbonImmutable::setTestNow();
    }

    public function test_binding_rotation_changes_scope_but_not_content_fingerprint_when_content_is_unchanged(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-28 22:00:00')
        );

        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(
            OperationalDeviceRegistry::class
        )->register(
            $admin,
            'POS scope rotation',
            [
                OperationalDeviceCapability::RestrictedOfflineReadModel,
            ]
        );

        $firstIssue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $firstResponse = $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $firstIssue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertOk();

        $firstFingerprint = (string) $firstResponse->json(
            'content_fingerprint'
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addMinute()
        );

        $secondIssue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $secondResponse = $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $secondIssue->token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertOk();

        $this->assertNotSame(
            $firstIssue->binding->public_id,
            $secondIssue->binding->public_id
        );

        $secondResponse
            ->assertJsonPath(
                'snapshot_version',
                2
            )
            ->assertJsonPath(
                'scope.binding_public_id',
                $secondIssue->binding->public_id
            )
            ->assertJsonPath(
                'scope.device_public_id',
                $device->public_id
            )
            ->assertJsonPath(
                'scope.binding_expires_at',
                $secondIssue->binding->expires_at->toAtomString()
            );

        $this->assertNotSame(
            $firstResponse->json('scope.binding_public_id'),
            $secondResponse->json('scope.binding_public_id')
        );
        $this->assertNotSame(
            $firstResponse->json('scope.binding_expires_at'),
            $secondResponse->json('scope.binding_expires_at')
        );
        $this->assertSame(
            $firstFingerprint,
            (string) $secondResponse->json(
                'content_fingerprint'
            )
        );

        CarbonImmutable::setTestNow();
    }

    public function test_availability_reader_exposes_balance_version_without_changing_pos_matrix_shape(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->active()
            ->orderBy('id')
            ->firstOrFail();

        $product = $this->product(
            'Mouse inalámbrico',
            'MOUSE-01'
        );

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '5.000000',
            'base_unit_code' => $product->base_unit_code,
            'version' => 11,
        ]);

        $position = app(
            InventoryAvailabilityReader::class
        )
            ->positions($admin)
            ->first(
                fn ($position): bool =>
                    $position->catalogProductId === $product->id
            );

        $this->assertNotNull($position);
        $this->assertSame(
            11,
            $position->balanceVersion
        );

        $matrix = app(
            CommerceSalePolicyGuard::class
        )->availabilityMatrix($admin);

        $key = implode(':', [
            $product->id,
            $location->id,
            InventoryCondition::New->value,
        ]);

        $this->assertSame(
            [
                'quantity' => '5.000000',
                'display' => '5',
                'location' => $location->name,
            ],
            $matrix[$key]
        );
    }

    private function snapshotFingerprint(string $token): string
    {
        return (string) $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $token
            )
            ->get(
                route(
                    'operational-runtime.read-model-snapshot.show'
                )
            )
            ->assertOk()
            ->json('content_fingerprint');
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'offline-snapshot-tests'],
                [
                    'name' => 'Offline snapshot tests',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])->refresh()
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();

        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }
}
