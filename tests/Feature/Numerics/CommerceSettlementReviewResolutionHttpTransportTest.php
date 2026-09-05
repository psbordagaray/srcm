<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementReviewRecorder;
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

final class CommerceSettlementReviewResolutionHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_http_post_records_resolution_without_business_effect(): void
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
            'http-resolution:review:001'
        );
        $originalReason = $review->reason;
        $salesBefore = CommerceSale::query()->count();
        $paymentsBefore = CommercePayment::query()->count();
        $receivablesBefore =
            CustomerReceivable::query()->count();

        $response = $this
            ->from('/commerce/sales/create')
            ->post(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                $this->payload(
                    'http-resolution:final:001'
                )
            );

        $response
            ->assertRedirect('/commerce/sales/create')
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            1
        );

        $resolution =
            CommerceSettlementReviewResolution::query()
                ->sole();

        $this->assertSame(
            $review->id,
            $resolution->commerce_settlement_review_id
        );
        $this->assertSame(
            CommerceSettlementReviewResolutionOutcome::
                RetryWithReferenceSettlement,
            $resolution->outcome
        );
        $this->assertSame(
            $originalReason,
            $review->refresh()->reason
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
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewResolutionManager::
                        AUDIT_EVENT
                )
                ->count()
        );
    }

    public function test_http_retry_is_idempotent_and_conflict_fails_closed(): void
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
            'http-resolution:review:idempotency-001'
        );
        $key = 'http-resolution:idempotent:001';
        $payload = $this->payload($key);

        $this->from('/commerce/sales/create')
            ->post(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                $payload
            )
            ->assertRedirect('/commerce/sales/create')
            ->assertSessionHasNoErrors();

        $this->from('/commerce/sales/create')
            ->post(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                $payload
            )
            ->assertRedirect('/commerce/sales/create')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            1
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewResolutionManager::
                        AUDIT_EVENT
                )
                ->count()
        );

        $conflicting = $payload;
        $conflicting['reason'] =
            'A conflicting HTTP resolution reason using the same key.';

        $this->from('/commerce/sales/create')
            ->post(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                $conflicting
            )
            ->assertRedirect('/commerce/sales/create')
            ->assertSessionHasErrors(
                'settlement_review_resolution'
            );

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            1
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewResolutionManager::
                        AUDIT_EVENT
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
            'http-resolution:review:authorization-001'
        );

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($operator);

        $this->post(
            route(
                'commerce-settlement-reviews.resolutions.store',
                $review
            ),
            $this->payload(
                'http-resolution:operator-denied:001'
            )
        )->assertForbidden();

        $other = Organization::query()->create([
            'name' => 'HTTP Resolution Other',
            'slug' => 'http-resolution-other',
            'active' => true,
        ]);
        $foreignAdmin = $this->actor(
            $other,
            UserRole::Admin
        );
        $this->actingAs($foreignAdmin);

        $this->post(
            route(
                'commerce-settlement-reviews.resolutions.store',
                $review
            ),
            $this->payload(
                'http-resolution:foreign-denied:001'
            )
        )->assertNotFound();

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            0
        );
    }

    public function test_accept_observed_is_rejected_by_enum_validation(): void
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
            'http-resolution:review:accept-observed-001'
        );

        $payload = $this->payload(
            'http-resolution:accept-observed:001'
        );
        $payload['outcome'] = 'accept_observed';

        $this->from('/commerce/sales/create')
            ->post(
                route(
                    'commerce-settlement-reviews.resolutions.store',
                    $review
                ),
                $payload
            )
            ->assertRedirect('/commerce/sales/create')
            ->assertSessionHasErrors('outcome');

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            0
        );
    }

    public function test_gate_route_preflight_and_immutable_boundaries_are_exact(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy[
                'commerce_settlement_review_resolution_http_transport_foundation_version'
            ]
        );
        $this->assertSame(
            \App\Http\Requests\StoreCommerceSettlementReviewResolution::class,
            $policy[
                'commerce_settlement_review_resolution_http_request_class'
            ]
        );
        $this->assertSame(
            \App\Http\Controllers\CommerceSettlementReviewResolutionController::class,
            $policy[
                'commerce_settlement_review_resolution_http_controller_class'
            ]
        );
        $this->assertSame(
            'resolve-commerce-settlement-review',
            $policy[
                'commerce_settlement_review_resolution_http_gate'
            ]
        );
        $this->assertSame(
            'commerce-settlement-reviews.resolutions.store',
            $policy[
                'commerce_settlement_review_resolution_http_route'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_http_store_wired'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_http_ui_view_wired'
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
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_runtime_controller_route_ui'
            ]
        );

        $route = Route::getRoutes()->getByName(
            'commerce-settlement-reviews.resolutions.store'
        );

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(
            'commerce/settlement-reviews/{commerceSettlementReview}/resolutions',
            $route->uri()
        );
        $this->assertTrue(
            Route::has(
                'commerce-settlement-reviews.resolutions.create'
            )
        );
        $this->assertContains(
            'can:resolve-commerce-settlement-review',
            $route->gatherMiddleware()
        );

        $this->assertTrue(
            app(ReleasePreflightInspector::class)
                ->inspect()['static'][
                    'p13_numeric_integrity_policy_contract'
                ]
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
            'b834eb143f192d117c9f448af48fb8e3f0af8523',
            $this->gitBlobSha1(
                app_path(
                    'Http/Controllers/CommerceSaleController.php'
                )
            )
        );
        $this->assertSame(
            '6d746dde938d26270ce3d0bcc87ceb34445a1755',
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

    /**
     * @return array<string, string|null>
     */
    private function payload(string $key): array
    {
        return [
            'outcome' =>
                CommerceSettlementReviewResolutionOutcome::
                    RetryWithReferenceSettlement->value,
            'reason' =>
                'Retry settlement using the preserved system reference total.',
            'notes' =>
                'HTTP transport records disposition only.',
            'idempotency_key' => $key,
        ];
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
                    'Preserve system total for HTTP resolution transport.'
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

        $canonicalContent = str_replace("\r\n", "\n", $content);

        return sha1(
            'blob '.strlen($canonicalContent)."\0".$canonicalContent
        );
    }
}
