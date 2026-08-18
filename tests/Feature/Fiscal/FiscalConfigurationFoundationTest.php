<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\FiscalOrganizationProfile;
use App\Models\FiscalPointOfSale;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalConfigurationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_enums_routes_and_admin_boundary_are_explicit(): void
    {
        $admin = $this->user(UserRole::Admin);
        $operator = $this->user(UserRole::Operator);

        $this->assertTrue(Schema::hasColumns(
            'fiscal_organization_profiles',
            [
                'organization_id',
                'legal_name',
                'tax_id',
                'vat_condition_code',
                'activity_started_on',
                'country_code',
            ]
        ));
        $this->assertTrue(Schema::hasColumns(
            'fiscal_points_of_sale',
            [
                'organization_id',
                'fiscal_organization_profile_id',
                'public_id',
                'environment',
                'point_number',
                'integration_mode',
                'active',
            ]
        ));
        $this->assertSame('homologation', FiscalEnvironment::Homologation->value);
        $this->assertSame('wsfe_v1', FiscalIntegrationMode::WsfeV1->value);
        $this->assertTrue(Route::has('fiscal-configuration.index'));
        $this->assertTrue(Route::has('fiscal-configuration.points.store'));

        $this->actingAs($admin)
            ->get(route('fiscal-configuration.index'))
            ->assertOk();
        $this->actingAs($operator)
            ->get(route('fiscal-configuration.index'))
            ->assertForbidden();
    }

    public function test_profile_is_normalized_audited_and_idempotent(): void
    {
        $admin = $this->user(UserRole::Admin);
        $manager = app(FiscalOrganizationProfileManager::class);
        $first = $manager->save($this->profileData(), $admin);
        $retry = $manager->save($this->profileData(), $admin);

        $this->actingAs($admin)
            ->put(route('fiscal-configuration.profile.update'), [
                'legal_name' => 'Empresa Fiscal de Prueba SA',
                'tax_id' => '20-12345678-6',
                'vat_condition_code' => '1',
                'gross_income_number' => '901-123456-7',
                'activity_started_on' => '2020-01-01',
                'address_line' => 'Calle Prueba 123',
                'city' => 'Córdoba',
                'province_code' => 'AR-C',
                'postal_code' => 'X5000ABC',
            ])
            ->assertRedirect(route('fiscal-configuration.index'));

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('20123456786', $first->tax_id);
        $this->assertSame('AR-C', $first->province_code);
        $this->assertSame('AR', $first->country_code);
        $this->assertDatabaseCount('fiscal_organization_profiles', 1);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $first->organization_id,
            'auditable_type' => FiscalOrganizationProfile::class,
            'auditable_id' => (string) $first->id,
            'event' => 'fiscal_organization_profile_created',
        ]);
    }

    public function test_invalid_cuit_is_rejected_by_domain(): void
    {
        $admin = $this->user(UserRole::Admin);
        $rejected = false;

        try {
            app(FiscalOrganizationProfileManager::class)->save(
                new FiscalOrganizationProfileData(
                    legalName: 'Empresa Fiscal de Prueba',
                    taxId: '20-12345678-0',
                    vatConditionCode: '1',
                    grossIncomeNumber: null,
                    activityStartedOn: '2020-01-01',
                    addressLine: 'Calle Prueba 123',
                    city: 'Córdoba',
                    provinceCode: 'AR-X',
                    postalCode: '5000'
                ),
                $admin
            );
        } catch (DomainException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertDatabaseCount('fiscal_organization_profiles', 0);
    }

    public function test_point_requires_profile_and_is_tenant_scoped(): void
    {
        $firstAdmin = $this->user(UserRole::Admin);
        $manager = app(FiscalPointOfSaleManager::class);
        $missingProfileRejected = false;

        try {
            $manager->create($this->pointData(), $firstAdmin);
        } catch (DomainException) {
            $missingProfileRejected = true;
        }

        $this->assertTrue($missingProfileRejected);

        app(FiscalOrganizationProfileManager::class)
            ->save($this->profileData(), $firstAdmin);
        $point = $manager->create($this->pointData(), $firstAdmin);

        $second = $this->organization('Organización Fiscal Ajena');
        $secondAdmin = $this->user(UserRole::Admin, $second);
        $crossTenantRejected = false;

        try {
            $manager->toggleActive($point, $secondAdmin);
        } catch (DomainException) {
            $crossTenantRejected = true;
        }

        $this->assertTrue($crossTenantRejected);
        $this->assertTrue($point->fresh()->active);
    }

    public function test_point_creation_is_idempotent_and_environment_specific(): void
    {
        $admin = $this->user(UserRole::Admin);
        app(FiscalOrganizationProfileManager::class)
            ->save($this->profileData(), $admin);
        $manager = app(FiscalPointOfSaleManager::class);
        $homologation = $manager->create($this->pointData(), $admin);
        $retry = $manager->create($this->pointData(), $admin);

        $this->actingAs($admin)
            ->post(route('fiscal-configuration.points.store'), [
                'point_number' => 1,
                'environment' => FiscalEnvironment::Production->value,
                'integration_mode' => FiscalIntegrationMode::WsfeV1->value,
            ])
            ->assertRedirect(route('fiscal-configuration.index'));

        $production = FiscalPointOfSale::query()
            ->where('environment', FiscalEnvironment::Production->value)
            ->sole();

        $this->assertSame($homologation->id, $retry->id);
        $this->assertNotSame($homologation->id, $production->id);
        $this->assertSame(
            FiscalEnvironment::Homologation,
            $homologation->environment
        );
        $this->assertSame(
            FiscalEnvironment::Production,
            $production->environment
        );
        $this->assertDatabaseCount('fiscal_points_of_sale', 2);
    }

    public function test_point_identity_is_immutable_and_only_active_toggles(): void
    {
        $admin = $this->user(UserRole::Admin);
        app(FiscalOrganizationProfileManager::class)
            ->save($this->profileData(), $admin);
        $manager = app(FiscalPointOfSaleManager::class);
        $point = $manager->create($this->pointData(), $admin);
        $modelRejected = false;
        $taxIdChangeRejected = false;

        try {
            app(FiscalOrganizationProfileManager::class)->save(
                $this->profileData('20-98765432-6'),
                $admin
            );
        } catch (DomainException) {
            $taxIdChangeRejected = true;
        }

        try {
            $point->integration_mode = FiscalIntegrationMode::Wsmtxca;
            $point->save();
        } catch (DomainException) {
            $modelRejected = true;
        }

        $this->assertTrue($taxIdChangeRejected);
        $this->assertTrue($modelRejected);
        $this->assertFalse($manager->toggleActive($point, $admin)->active);
        $this->assertQueryRejected(
            fn () => DB::table('fiscal_points_of_sale')
                ->where('id', $point->id)
                ->update(['point_number' => 2])
        );

        $deleteRejected = false;

        try {
            $point->fresh()->delete();
        } catch (DomainException) {
            $deleteRejected = true;
        }

        $this->assertTrue($deleteRejected);
        $this->assertDatabaseHas('fiscal_points_of_sale', [
            'id' => $point->id,
            'point_number' => 1,
            'active' => false,
        ]);
    }

    public function test_commerce_remains_independent_and_no_fiscal_document_exists(): void
    {
        $this->assertFalse(Schema::hasColumn(
            'commerce_sales',
            'fiscal_point_of_sale_id'
        ));
        $this->assertFalse(Schema::hasColumn(
            'commerce_sales',
            'fiscal_status'
        ));
        $this->assertFalse(Schema::hasTable('fiscal_documents'));
        $this->assertFalse(Schema::hasTable('fiscal_authorizations'));
        $this->assertFalse(Route::has('fiscal-documents.authorize'));
        $this->assertFalse(Route::has('fiscal-configuration.destroy'));
        $this->assertFalse(Route::has('fiscal-configuration.points.destroy'));
    }

    private function profileData(
        string $taxId = '20-12345678-6'
    ): FiscalOrganizationProfileData
    {
        return new FiscalOrganizationProfileData(
            legalName: '  Empresa Fiscal de Prueba SA  ',
            taxId: $taxId,
            vatConditionCode: '1',
            grossIncomeNumber: ' 901-123456-7 ',
            activityStartedOn: '2020-01-01',
            addressLine: '  Calle Prueba 123 ',
            city: ' Córdoba ',
            provinceCode: 'ar-c',
            postalCode: ' x5000abc '
        );
    }

    private function pointData(): FiscalPointOfSaleData
    {
        return new FiscalPointOfSaleData(
            pointNumber: 1,
            environment: FiscalEnvironment::Homologation,
            integrationMode: FiscalIntegrationMode::WsfeV1
        );
    }

    private function user(
        UserRole $role,
        ?Organization $organization = null
    ): User {
        $organization ??= Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
            'current_organization_id' => $organization->id,
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role->value,
                    'active' => true,
                ]
            )
        );

        return $user;
    }

    private function organization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function assertQueryRejected(callable $operation): void
    {
        $rejected = false;

        try {
            $operation();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }
}
