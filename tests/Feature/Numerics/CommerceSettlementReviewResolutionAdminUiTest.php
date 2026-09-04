<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementReviewRecorder;
use App\Domain\Commerce\CommerceSettlementReviewResolutionData;
use App\Domain\Commerce\CommerceSettlementReviewResolutionManager;
use App\Domain\Release\ReleasePreflightInspector;
use App\Enums\CommerceSettlementReviewResolutionOutcome;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CommercePayment;
use App\Models\CommerceSale;
use App\Models\CommerceSettlementReview;
use App\Models\CommerceSettlementReviewResolution;
use App\Models\CustomerReceivable;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CommerceSettlementReviewResolutionAdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_get_unresolved_ui_is_read_only_and_reuses_store_route(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'admin-ui:review:unresolved:001'
        );

        $salesBefore = CommerceSale::query()->count();
        $paymentsBefore = CommercePayment::query()->count();
        $receivablesBefore = CustomerReceivable::query()->count();
        $resolutionsBefore =
            CommerceSettlementReviewResolution::query()->count();
        $resolutionAuditsBefore = AuditLog::query()
            ->where(
                'event',
                CommerceSettlementReviewResolutionManager::AUDIT_EVENT
            )
            ->count();

        $response = $this->get(
            route(
                'commerce-settlement-reviews.resolutions.create',
                $review
            )
        );

        $response
            ->assertOk()
            ->assertSee('Resolución administrativa')
            ->assertSee((string) $review->system_total_minor)
            ->assertSee((string) $review->settled_total_minor)
            ->assertSee((string) $review->final_value_minor)
            ->assertSee($review->reason)
            ->assertSee($review->warning_code)
            ->assertSee(
                CommerceSettlementReviewResolutionOutcome::
                    RetryWithReferenceSettlement->value,
                false
            )
            ->assertSee(
                CommerceSettlementReviewResolutionOutcome::
                    AbandonCheckout->value,
                false
            )
            ->assertDontSee('accept_observed', false)
            ->assertSee(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                false
            )
            ->assertSee('name="idempotency_key"', false)
            ->assertSee(
                'data-settlement-review-resolution-form="true"',
                false
            );

        $idempotencyKey = $response->viewData(
            'idempotencyKey'
        );

        $this->assertIsString($idempotencyKey);
        $this->assertMatchesRegularExpression(
            '/\Aui:commerce-settlement-review-resolution:'.
            '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-'.
            '[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $idempotencyKey
        );

        $this->assertCount(
            2,
            CommerceSettlementReviewResolutionOutcome::cases()
        );
        $this->assertSame(
            $salesBefore,
            CommerceSale::query()->count()
        );
        $this->assertSame(
            $paymentsBefore,
            CommercePayment::query()->count()
        );
        $this->assertSame(
            $receivablesBefore,
            CustomerReceivable::query()->count()
        );
        $this->assertSame(
            $resolutionsBefore,
            CommerceSettlementReviewResolution::query()->count()
        );
        $this->assertSame(
            $resolutionAuditsBefore,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewResolutionManager::AUDIT_EVENT
                )
                ->count()
        );
    }

    public function test_resolved_ui_shows_immutable_evidence_without_second_form(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'admin-ui:review:resolved:001'
        );

        $resolution = app(
            CommerceSettlementReviewResolutionManager::class
        )->resolve(
            new CommerceSettlementReviewResolutionData(
                commerceSettlementReviewId: $review->id,
                outcome:
                    CommerceSettlementReviewResolutionOutcome::
                        AbandonCheckout,
                reason:
                    'Administrative decision abandons this checkout safely.',
                notes:
                    'Recorded by the administrative UI focal.',
                idempotencyKey:
                    'admin-ui:resolved:resolution:001'
            ),
            $admin
        );

        $resolutionCountBefore =
            CommerceSettlementReviewResolution::query()->count();
        $auditCountBefore = AuditLog::query()
            ->where(
                'event',
                CommerceSettlementReviewResolutionManager::AUDIT_EVENT
            )
            ->count();

        $response = $this->get(
            route(
                'commerce-settlement-reviews.resolutions.create',
                $review
            )
        );

        $response
            ->assertOk()
            ->assertSee('Resolución registrada')
            ->assertSee($resolution->public_id)
            ->assertSee($resolution->outcome->value, false)
            ->assertSee($resolution->reason)
            ->assertSee((string) $resolution->notes)
            ->assertDontSee(
                'data-settlement-review-resolution-form',
                false
            )
            ->assertDontSee('name="idempotency_key"', false);

        $this->assertNull(
            $response->viewData('idempotencyKey')
        );

        $this->assertSame(
            $resolutionCountBefore,
            CommerceSettlementReviewResolution::query()->count()
        );
        $this->assertSame(
            $auditCountBefore,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewResolutionManager::AUDIT_EVENT
                )
                ->count()
        );
    }

    public function test_operator_is_forbidden_and_foreign_organization_is_not_found(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'admin-ui:review:authorization:001'
        );

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($operator);

        $this->get(
            route(
                'commerce-settlement-reviews.resolutions.create',
                $review
            )
        )->assertForbidden();

        $other = Organization::query()->create([
            'name' => 'Admin UI Other',
            'slug' => 'admin-ui-other',
            'active' => true,
        ]);

        $foreignAdmin = $this->actor(
            $other,
            UserRole::Admin
        );
        $this->actingAs($foreignAdmin);

        $this->get(
            route(
                'commerce-settlement-reviews.resolutions.create',
                $review
            )
        )->assertNotFound();

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            0
        );
    }

    public function test_create_route_release_metadata_and_immutable_boundaries_are_exact(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy[
                'commerce_settlement_review_resolution_admin_ui_foundation_version'
            ]
        );
        $this->assertSame(
            'commerce-settlement-reviews.resolutions.create',
            $policy[
                'commerce_settlement_review_resolution_admin_ui_route'
            ]
        );
        $this->assertSame(
            'commerce-settlement-reviews.resolution-create',
            $policy[
                'commerce_settlement_review_resolution_admin_ui_view'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_http_ui_view_wired'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_runtime_controller_route_ui'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_http_store_wired'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_http_outcome_execution_wired'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_http_retry_execution_wired'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_http_business_mutation_authorized'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_http_accept_observed_authorized'
            ]
        );

        $createRoute = Route::getRoutes()->getByName(
            'commerce-settlement-reviews.resolutions.create'
        );
        $storeRoute = Route::getRoutes()->getByName(
            'commerce-settlement-reviews.resolutions.store'
        );

        $this->assertNotNull($createRoute);
        $this->assertNotNull($storeRoute);
        $this->assertSame(
            ['GET', 'HEAD'],
            $createRoute->methods()
        );
        $this->assertSame(
            'commerce/settlement-reviews/{commerceSettlementReview}/resolutions/create',
            $createRoute->uri()
        );
        $this->assertContains(
            'can:resolve-commerce-settlement-review',
            $createRoute->gatherMiddleware()
        );
        $this->assertArrayHasKey(
            'commerceSettlementReview',
            $createRoute->wheres
        );
        $this->assertSame(
            ['POST'],
            $storeRoute->methods()
        );
        $this->assertSame(
            'commerce/settlement-reviews/{commerceSettlementReview}/resolutions',
            $storeRoute->uri()
        );

        $this->assertTrue(
            app(ReleasePreflightInspector::class)
                ->inspect()['static'][
                    'p13_numeric_integrity_policy_contract'
                ]
        );

        $this->assertSame(
            'b42257f5294b1e0ab49bf0dd1f333d763a09c2609db1aac89165cb1711811e9a',
            hash_file(
                'sha256',
                app_path(
                    'Http/Requests/StoreCommerceSettlementReviewResolution.php'
                )
            )
        );
        $this->assertSame(
            '437088b4a452eadf693de4d786f146c5c64299b696829f4dee7ad8401882a7e9',
            hash_file(
                'sha256',
                app_path(
                    'Domain/Commerce/CommerceSettlementReviewResolutionManager.php'
                )
            )
        );
        $this->assertSame(
            '7fc82b48a34c21b3b688d020653ca2f1c99559b2a61cdab421f48844120df851',
            hash_file(
                'sha256',
                app_path(
                    'Domain/Commerce/CommerceSettlementReviewResolutionData.php'
                )
            )
        );
        $this->assertSame(
            '2375b66a6d1c7006a04b75095119f6c658cf487b84282f7fd33564dcfb1b71fa',
            hash_file(
                'sha256',
                app_path(
                    'Models/CommerceSettlementReviewResolution.php'
                )
            )
        );
        $this->assertSame(
            'bd3984f9b3205c48d829042c6c804d32cee8f8008f9515405fc7ecd6d400ab30',
            hash_file(
                'sha256',
                app_path(
                    'Enums/CommerceSettlementReviewResolutionOutcome.php'
                )
            )
        );
        $this->assertSame(
            '4f185e50cfc1ab73a9c830ce793e13a5a84ceccbb5b6b88076c7e3957103d095',
            hash_file(
                'sha256',
                app_path(
                    'Models/CommerceSettlementReview.php'
                )
            )
        );
        $this->assertSame(
            'daaf2d61c9b5cb9a8d5c2ff17c00ae97ea4004bad5ab38c2972e4eca22c616ff',
            hash_file(
                'sha256',
                app_path('Enums/UserRole.php')
            )
        );
        $this->assertSame(
            'b834eb143f192d117c9f448af48fb8e3f0af8523',
            $this->gitBlobSha1(
                app_path(
                    'Http/Controllers/CommerceSaleController.php'
                )
            )
        );
        $this->assertSame(
            'eb9e31c9c2155f568bdff14696261d4b6b335adf',
            $this->gitBlobSha1(
                app_path(
                    'Domain/Commerce/CommerceCheckoutManager.php'
                )
            )
        );

        $controller = file_get_contents(
            app_path(
                'Http/Controllers/CommerceSettlementReviewResolutionController.php'
            )
        );

        $this->assertIsString($controller);
        $this->assertStringContainsString(
            "view(\n            'commerce-settlement-reviews.resolution-create'",
            $controller
        );
        $this->assertStringContainsString(
            "'ui:commerce-settlement-review-resolution:'",
            $controller
        );

        foreach ([
            'CommerceCheckoutManager',
            'CommerceSale',
            'CommercePayment',
            'CustomerReceivable',
            'acceptObserved',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $controller
            );
        }
    }

    private function review(
        Organization $organization,
        User $actor,
        string $key,
    ): CommerceSettlementReview {
        return app(
            CommerceSettlementReviewRecorder::class
        )->record(
            exception: $this->decisionException(),
            checkoutIdempotencyKey: $key,
            organizationId: $organization->id,
            actor: $actor,
        );
    }

    private function decisionException(): CommerceSettlementDiscrepancyDecisionException
    {
        return CommerceSettlementDiscrepancyDecisionException::fromInput(
            runtimeEvidence:
                new CommerceSettlementDiscrepancyException(
                    systemTotalMinor: 10000,
                    settledTotalMinor: 9000,
                    observedComponentIds: [
                        'payments.0.amount',
                    ],
                    componentAnalyses: [],
                    missingTransportEvidenceComponentIds: [
                        'payments.0.amount',
                    ],
                ),
            input:
                CommerceSettlementDiscrepancyDecisionInput::keepReference(
                    'Preserve system total for administrative UI resolution.'
                ),
        );
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function actor(
        Organization $organization,
        UserRole $role,
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $user->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' =>
                            $organization->id,
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

    private function gitBlobSha1(string $path): string
    {
        $content = file_get_contents($path);

        $this->assertIsString($content);

        return sha1(
            'blob '.strlen($content)."\0".$content
        );
    }
}
