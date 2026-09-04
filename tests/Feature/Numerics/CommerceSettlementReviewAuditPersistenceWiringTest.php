<?php

namespace Tests\Feature\Numerics;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementReviewRecorder;
use App\Domain\Release\ReleasePreflightInspector;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CommerceSettlementReview;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceSettlementReviewAuditPersistenceWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_new_review_creates_exactly_one_compact_audit_and_retries_do_not_duplicate_it(): void
    {
        $organization = $this->defaultOrganization();
        $actor = $this->actor($organization);
        $this->actingAs($actor);

        $recorder = app(CommerceSettlementReviewRecorder::class);
        $key = 'audit:checkout:review-001';

        $review = $recorder->record(
            exception: $this->decisionException(
                'Preserve system total and audit review creation.'
            ),
            checkoutIdempotencyKey: $key,
            organizationId: $organization->id,
            actor: $actor,
        );

        $this->assertDatabaseCount(
            'commerce_settlement_reviews',
            1
        );

        $audit = AuditLog::query()
            ->where(
                'event',
                CommerceSettlementReviewRecorder::AUDIT_EVENT
            )
            ->sole();

        $this->assertSame(
            $organization->id,
            $audit->organization_id
        );
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(
            CommerceSettlementReview::class,
            $audit->auditable_type
        );
        $this->assertSame(
            (string) $review->id,
            $audit->auditable_id
        );
        $this->assertNull($audit->old_values);
        $this->assertSame(
            [
                'checkout_idempotency_key' =>
                    $review->checkout_idempotency_key,
                'decision' => $review->decision,
                'final_value_minor' =>
                    $review->final_value_minor,
                'public_id' => $review->public_id,
                'requested_at' =>
                    $review->requested_at->format(DATE_ATOM),
                'requested_by_user_id' =>
                    $review->requested_by_user_id,
                'review_fingerprint' =>
                    $review->review_fingerprint,
                'settled_total_minor' =>
                    $review->settled_total_minor,
                'system_total_minor' =>
                    $review->system_total_minor,
                'warning_code' => $review->warning_code,
            ],
            $audit->new_values
        );

        $same = $recorder->record(
            exception: $this->decisionException(
                'Preserve system total and audit review creation.'
            ),
            checkoutIdempotencyKey: $key,
            organizationId: $organization->id,
            actor: $actor,
        );

        $this->assertSame($review->id, $same->id);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewRecorder::AUDIT_EVENT
                )
                ->count()
        );

        try {
            $recorder->record(
                exception: $this->decisionException(
                    'A conflicting explicit reason.'
                ),
                checkoutIdempotencyKey: $key,
                organizationId: $organization->id,
                actor: $actor,
            );

            $this->fail(
                'A conflicting settlement review was accepted.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'La liquidación ya posee otra revisión para la misma clave de checkout.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'commerce_settlement_reviews',
            1
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewRecorder::AUDIT_EVENT
                )
                ->count()
        );
    }

    public function test_audit_failure_rolls_back_new_review_fail_closed(): void
    {
        $organization = $this->defaultOrganization();
        $actor = $this->actor($organization);
        $this->actingAs($actor);

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new DomainException(
                            'Forced settlement review audit failure.'
                        )
                    );
            }
        );

        try {
            app(CommerceSettlementReviewRecorder::class)->record(
                exception: $this->decisionException(
                    'Review must roll back with failed audit.'
                ),
                checkoutIdempotencyKey:
                    'audit:checkout:review-failure-001',
                organizationId: $organization->id,
                actor: $actor,
            );

            $this->fail(
                'A settlement review survived a failed audit.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Forced settlement review audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'commerce_settlement_reviews',
            0
        );
        $this->assertSame(
            0,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewRecorder::AUDIT_EVENT
                )
                ->count()
        );
    }

    public function test_audit_policy_preflight_and_business_boundaries_are_coherent(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_audit_persistence'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewRecorder::AUDIT_EVENT,
            $policy[
                'commerce_settlement_review_persistence_audit_event'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewRecorder::AUDIT_WIRING_STATUS,
            $policy[
                'commerce_settlement_review_persistence_audit_wiring_status'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_audit_exactly_once'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_audit_atomic_with_review_create'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_audit_failure_rolls_back_review'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_persistence_audit_full_evidence_duplicated'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_persistence_sale_confirmation_authorized'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_persistence_business_mutation_authorized'
            ]
        );

        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $recorder = file_get_contents(
            app_path(
                'Domain/Commerce/CommerceSettlementReviewRecorder.php'
            )
        );

        $this->assertIsString($manager);
        $this->assertIsString($controller);
        $this->assertIsString($recorder);
        $this->assertStringNotContainsString(
            'CommerceSettlementReviewRecorder',
            $manager
        );
        $this->assertStringNotContainsString(
            'AuditRecorder',
            $manager
        );
        $this->assertStringContainsString(
            'CommerceSettlementReviewRecorder',
            $controller
        );
        $this->assertStringNotContainsString(
            'AuditRecorder',
            $controller
        );
        $this->assertStringContainsString(
            'AuditRecorder',
            $recorder
        );
        $this->assertStringContainsString(
            'DB::transaction',
            $recorder
        );

        $this->assertFalse(
            method_exists(
                CommerceSettlementDiscrepancyDecisionEvidence::class,
                'acceptObserved'
            )
        );

        $preflight = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $preflight['static']['p13_numeric_integrity_policy_contract']
        );
        $this->assertFalse($preflight['production_authorized']);
    }

    private function decisionException(
        string $reason
    ): CommerceSettlementDiscrepancyDecisionException {
        return CommerceSettlementDiscrepancyDecisionException::
            fromInput(
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
                    CommerceSettlementDiscrepancyDecisionInput::
                        keepReference($reason),
            );
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function actor(
        Organization $organization
    ): User {
        $user = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
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
                        'role' => UserRole::Operator->value,
                        'active' => true,
                    ]
                )
        );

        return $user;
    }
}
