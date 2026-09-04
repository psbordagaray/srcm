<?php

namespace Tests\Feature\Numerics;

use App\Domain\Audit\AuditRecorder;
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
use App\Models\CommerceSettlementReview;
use App\Models\CommerceSettlementReviewResolution;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommerceSettlementReviewResolutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_resolves_separately_and_original_review_remains_immutable(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'resolution:review:001'
        );
        $originalReason = $review->reason;

        $salesBefore = $this->tableCount('commerce_sales');
        $paymentsBefore = $this->tableCount('commerce_payments');

        $resolution = app(
            CommerceSettlementReviewResolutionManager::class
        )->resolve(
            new CommerceSettlementReviewResolutionData(
                commerceSettlementReviewId: $review->id,
                outcome:
                    CommerceSettlementReviewResolutionOutcome::
                        RetryWithReferenceSettlement,
                reason:
                    'Retry settlement using the preserved system reference total.',
                notes: 'No business mutation in foundation.',
                idempotencyKey: 'resolution:final:001',
            ),
            $admin
        );

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            1
        );
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
            $resolution->id,
            $review->refresh()->resolution?->id
        );
        $this->assertSame(
            $originalReason,
            $review->refresh()->reason
        );
        $this->assertSame(
            $salesBefore,
            $this->tableCount('commerce_sales')
        );
        $this->assertSame(
            $paymentsBefore,
            $this->tableCount('commerce_payments')
        );

        try {
            $review->forceFill([
                'reason' => 'Attempted mutation after resolution.',
            ])->save();

            $this->fail('The original settlement review was mutable.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Una revisión de liquidación registrada es inmutable.',
                $exception->getMessage()
            );
        }
    }

    public function test_capability_and_organization_scope_fail_closed(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'resolution:review:scope-001'
        );

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($operator);

        try {
            app(
                CommerceSettlementReviewResolutionManager::class
            )->resolve(
                $this->resolutionData(
                    $review,
                    'resolution:operator-denied-001'
                ),
                $operator
            );

            $this->fail('An operator resolved a settlement review.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'No posee permiso para resolver una revisión de liquidación.',
                $exception->getMessage()
            );
        }

        $other = Organization::query()->create([
            'name' => 'Resolution Scope Other',
            'slug' => 'resolution-scope-other',
            'active' => true,
        ]);
        $foreignAdmin = $this->actor($other, UserRole::Admin);
        $this->actingAs($foreignAdmin);

        try {
            app(
                CommerceSettlementReviewResolutionManager::class
            )->resolve(
                $this->resolutionData(
                    $review,
                    'resolution:foreign-org-001'
                ),
                $foreignAdmin
            );

            $this->fail('A foreign organization resolved the review.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'La revisión de liquidación no pertenece a la organización activa.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            0
        );
        $this->assertTrue(
            UserRole::Admin->canResolveCommerceSettlementReview()
        );
        $this->assertFalse(
            UserRole::Operator->canResolveCommerceSettlementReview()
        );
        $this->assertFalse(
            UserRole::Viewer->canResolveCommerceSettlementReview()
        );
    }

    public function test_idempotency_conflicts_second_final_and_audit_are_exact(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'resolution:review:idempotency-001'
        );
        $manager = app(
            CommerceSettlementReviewResolutionManager::class
        );
        $data = $this->resolutionData(
            $review,
            'resolution:idempotent-001'
        );

        $first = $manager->resolve($data, $admin);
        $same = $manager->resolve($data, $admin);

        $this->assertSame($first->id, $same->id);
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

        $audit = AuditLog::query()
            ->where(
                'event',
                CommerceSettlementReviewResolutionManager::AUDIT_EVENT
            )
            ->sole();

        $this->assertSame(
            CommerceSettlementReviewResolution::class,
            $audit->auditable_type
        );
        $this->assertSame(
            (string) $first->id,
            $audit->auditable_id
        );
        $this->assertSame(
            $review->public_id,
            $audit->new_values['review_public_id']
        );
        $this->assertSame(
            CommerceSettlementReviewResolutionOutcome::
                RetryWithReferenceSettlement->value,
            $audit->new_values['outcome']
        );

        try {
            $manager->resolve(
                new CommerceSettlementReviewResolutionData(
                    commerceSettlementReviewId: $review->id,
                    outcome:
                        CommerceSettlementReviewResolutionOutcome::
                            RetryWithReferenceSettlement,
                    reason:
                        'A conflicting resolution reason with the same key.',
                    notes: null,
                    idempotencyKey:
                        'resolution:idempotent-001',
                ),
                $admin
            );

            $this->fail('Conflicting idempotent content was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'La clave idempotente de resolución ya fue utilizada con otro contenido.',
                $exception->getMessage()
            );
        }

        try {
            $manager->resolve(
                $this->resolutionData(
                    $review,
                    'resolution:second-final-001'
                ),
                $admin
            );

            $this->fail('A second final resolution was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'La revisión de liquidación ya posee una resolución final.',
                $exception->getMessage()
            );
        }

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

    public function test_both_foundation_outcomes_persist_without_business_effect_and_accept_observed_is_absent(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $manager = app(
            CommerceSettlementReviewResolutionManager::class
        );
        $salesBefore = $this->tableCount('commerce_sales');
        $paymentsBefore = $this->tableCount('commerce_payments');

        $retryReview = $this->review(
            $organization,
            $admin,
            'resolution:review:retry-001'
        );
        $abandonReview = $this->review(
            $organization,
            $admin,
            'resolution:review:abandon-001'
        );

        $manager->resolve(
            new CommerceSettlementReviewResolutionData(
                commerceSettlementReviewId: $retryReview->id,
                outcome:
                    CommerceSettlementReviewResolutionOutcome::
                        RetryWithReferenceSettlement,
                reason:
                    'Retry later using corrected settlement inputs only.',
                notes: null,
                idempotencyKey: 'resolution:retry-outcome-001',
            ),
            $admin
        );

        $manager->resolve(
            new CommerceSettlementReviewResolutionData(
                commerceSettlementReviewId: $abandonReview->id,
                outcome:
                    CommerceSettlementReviewResolutionOutcome::
                        AbandonCheckout,
                reason:
                    'Abandon this checkout without creating business effects.',
                notes: null,
                idempotencyKey: 'resolution:abandon-outcome-001',
            ),
            $admin
        );

        $this->assertSame(
            [
                'retry_with_reference_settlement',
                'abandon_checkout',
            ],
            CommerceSettlementReviewResolutionOutcome::values()
        );
        $this->assertFalse(
            method_exists(
                CommerceSettlementReviewResolutionOutcome::class,
                'acceptObserved'
            )
        );
        $this->assertSame(
            $salesBefore,
            $this->tableCount('commerce_sales')
        );
        $this->assertSame(
            $paymentsBefore,
            $this->tableCount('commerce_payments')
        );
        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            2
        );

        $source = file_get_contents(
            app_path(
                'Domain/Commerce/CommerceSettlementReviewResolutionManager.php'
            )
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('CommerceSale', $source);
        $this->assertStringNotContainsString('CommercePayment', $source);
        $this->assertStringNotContainsString('CustomerReceivable', $source);
        $this->assertStringNotContainsString('acceptObserved', $source);
    }

    public function test_audit_failure_rolls_back_resolution(): void
    {
        $organization = $this->defaultOrganization();
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $review = $this->review(
            $organization,
            $admin,
            'resolution:review:audit-failure-001'
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new DomainException(
                            'Forced resolution audit failure.'
                        )
                    );
            }
        );

        try {
            app(
                CommerceSettlementReviewResolutionManager::class
            )->resolve(
                $this->resolutionData(
                    $review,
                    'resolution:audit-failure-001'
                ),
                $admin
            );

            $this->fail('Resolution survived a failed audit.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Forced resolution audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'commerce_settlement_review_resolutions',
            0
        );
    }

    public function test_release_preflight_and_runtime_boundaries_are_coherent(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertSame(
            CommerceSettlementReviewResolutionManager::
                FOUNDATION_VERSION,
            $policy[
                'commerce_settlement_review_resolution_foundation_version'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewResolutionManager::class,
            $policy[
                'commerce_settlement_review_resolution_manager_class'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewResolution::class,
            $policy[
                'commerce_settlement_review_resolution_model_class'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewResolutionOutcome::values(),
            $policy[
                'commerce_settlement_review_resolution_outcome_values'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_resolution_admin_only'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_sale_creation_authorized'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_accept_observed_authorized'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_resolution_runtime_controller_route_ui'
            ]
        );

        $this->assertTrue(
            app(ReleasePreflightInspector::class)
                ->inspect()['static'][
                    'p13_numeric_integrity_policy_contract'
                ]
        );

        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $checkoutManager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $request = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );
        $checkoutData = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutData.php')
        );
        $reviewRecorder = file_get_contents(
            app_path('Domain/Commerce/CommerceSettlementReviewRecorder.php')
        );

        foreach (
            [
                $controller,
                $checkoutManager,
                $request,
                $checkoutData,
                $reviewRecorder,
            ] as $source
        ) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'CommerceSettlementReviewResolutionManager',
                $source
            );
        }
    }

    private function resolutionData(
        CommerceSettlementReview $review,
        string $key,
    ): CommerceSettlementReviewResolutionData {
        return new CommerceSettlementReviewResolutionData(
            commerceSettlementReviewId: $review->id,
            outcome:
                CommerceSettlementReviewResolutionOutcome::
                    RetryWithReferenceSettlement,
            reason:
                'Retry settlement with the preserved system reference total.',
            notes: null,
            idempotencyKey: $key,
        );
    }

    private function review(
        Organization $organization,
        User $actor,
        string $key,
    ): CommerceSettlementReview {
        return app(CommerceSettlementReviewRecorder::class)->record(
            exception: $this->decisionException(
                'Preserve system total for settlement review resolution.'
            ),
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
                    observedComponentIds: ['payments.0.amount'],
                    componentAnalyses: [],
                    missingTransportEvidenceComponentIds: [
                        'payments.0.amount',
                    ],
                ),
            input:
                CommerceSettlementDiscrepancyDecisionInput::keepReference(
                    'Preserve system total for settlement review resolution.'
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
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
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

    private function tableCount(string $table): int
    {
        return (int) $this->app['db']
            ->table($table)
            ->count();
    }
}
