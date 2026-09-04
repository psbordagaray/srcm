<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementReviewRecorder;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Enums\UserRole;
use App\Models\CommerceSettlementReview;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceSettlementReviewPersistenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_persists_canonical_pre_sale_keep_reference_review(): void
    {
        $organization = $this->defaultOrganization();
        $actor = $this->actor($organization);
        $exception = $this->decisionException(
            'Preserve system total for settlement review.'
        );

        $review = app(
            CommerceSettlementReviewRecorder::class
        )->record(
            exception: $exception,
            checkoutIdempotencyKey:
                'foundation:checkout:review-001',
            organizationId: $organization->id,
            actor: $actor,
        );

        $this->assertSame(
            $organization->id,
            $review->organization_id
        );
        $this->assertSame(
            'foundation:checkout:review-001',
            $review->checkout_idempotency_key
        );
        $this->assertTrue(Str::isUuid($review->public_id));
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $review->review_fingerprint
        );
        $this->assertSame(10000, $review->system_total_minor);
        $this->assertSame(9000, $review->settled_total_minor);
        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference->value,
            $review->decision
        );
        $this->assertSame(10000, $review->final_value_minor);
        $this->assertSame(
            'Preserve system total for settlement review.',
            $review->reason
        );
        $this->assertSame(
            $exception->decisionEvidence::WARNING_CODE,
            $review->warning_code
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::SCHEMA,
            $review->runtime_evidence_snapshot['schema']
        );
        $this->assertSame(
            $exception->decisionEvidence::SCHEMA,
            $review->decision_evidence_snapshot['schema']
        );
        $this->assertSame(
            $actor->id,
            $review->requested_by_user_id
        );
        $this->assertNotNull($review->requested_at);
        $this->assertNotNull($review->created_at);
        $this->assertFalse(
            Schema::hasColumn(
                'commerce_settlement_reviews',
                'commerce_sale_id'
            )
        );
    }

    public function test_recorder_is_idempotent_and_conflicting_review_fails_closed(): void
    {
        $organization = $this->defaultOrganization();
        $actor = $this->actor($organization);
        $recorder = app(
            CommerceSettlementReviewRecorder::class
        );
        $key = 'foundation:checkout:review-002';

        $first = $recorder->record(
            exception: $this->decisionException(
                'Preserve canonical system total.'
            ),
            checkoutIdempotencyKey: $key,
            organizationId: $organization->id,
            actor: $actor,
        );

        $same = $recorder->record(
            exception: $this->decisionException(
                'Preserve canonical system total.'
            ),
            checkoutIdempotencyKey: $key,
            organizationId: $organization->id,
            actor: $actor,
        );

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount(
            'commerce_settlement_reviews',
            1
        );

        try {
            $recorder->record(
                exception: $this->decisionException(
                    'A different explicit review reason.'
                ),
                checkoutIdempotencyKey: $key,
                organizationId: $organization->id,
                actor: $actor,
            );

            $this->fail(
                'A conflicting review reused the same checkout key.'
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
    }

    public function test_review_is_tenant_scoped_and_requires_active_membership(): void
    {
        $first = $this->defaultOrganization();
        $second = $this->organization(
            'Settlement Review Second Tenant'
        );
        $actor = $this->actor($first);

        $review = app(
            CommerceSettlementReviewRecorder::class
        )->record(
            exception: $this->decisionException(
                'First tenant review.'
            ),
            checkoutIdempotencyKey:
                'foundation:checkout:tenant-001',
            organizationId: $first->id,
            actor: $actor,
        );

        $this->assertSame($first->id, $review->organization_id);

        try {
            app(
                CommerceSettlementReviewRecorder::class
            )->record(
                exception: $this->decisionException(
                    'Cross-tenant review attempt.'
                ),
                checkoutIdempotencyKey:
                    'foundation:checkout:tenant-001',
                organizationId: $second->id,
                actor: $actor,
            );

            $this->fail(
                'An actor without active membership created a cross-tenant review.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'La revisión de liquidación no conserva identidad, evidencia y trazabilidad válidas.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            1,
            CommerceSettlementReview::query()->count()
        );
        $this->assertSame(
            1,
            CommerceSettlementReview::query()
                ->forOrganization($first->id)
                ->count()
        );
        $this->assertSame(
            0,
            CommerceSettlementReview::query()
                ->forOrganization($second->id)
                ->count()
        );
    }

    public function test_persisted_review_is_immutable(): void
    {
        $organization = $this->defaultOrganization();
        $actor = $this->actor($organization);

        $review = app(
            CommerceSettlementReviewRecorder::class
        )->record(
            exception: $this->decisionException(
                'Immutable review.'
            ),
            checkoutIdempotencyKey:
                'foundation:checkout:immutable-001',
            organizationId: $organization->id,
            actor: $actor,
        );

        try {
            $review->reason = 'Tampered';
            $review->save();

            $this->fail(
                'A persisted settlement review was updated.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Una revisión de liquidación registrada es inmutable.',
                $exception->getMessage()
            );
        }

        $review->refresh();

        try {
            $review->delete();

            $this->fail(
                'A persisted settlement review was deleted.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Una revisión de liquidación registrada no puede eliminarse.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'commerce_settlement_reviews',
            ['id' => $review->id]
        );
    }

    public function test_foundation_is_not_runtime_wired_and_hard_fail_remains(): void
    {
        $manager = file_get_contents(
            app_path(
                'Domain/Commerce/CommerceCheckoutManager.php'
            )
        );
        $controller = file_get_contents(
            app_path(
                'Http/Controllers/CommerceSaleController.php'
            )
        );

        $this->assertIsString($manager);
        $this->assertIsString($controller);
        $this->assertSame(
            'FOUNDATION_ONLY_NOT_RUNTIME_WIRED',
            CommerceSettlementReviewRecorder::
                RUNTIME_WIRING_STATUS
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementReviewRecorder',
            $manager
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementReviewRecorder',
            $controller
        );
        $this->assertStringContainsString(
            'throw CommerceSettlementDiscrepancyDecisionException::',
            $manager
        );
        $this->assertStringContainsString(
            '$sale = CommerceSale::query()->create([',
            $manager
        );
        $this->assertFalse(
            method_exists(
                CommerceSettlementReview::class,
                'sale'
            )
        );
    }

    private function decisionException(
        string $reason
    ): CommerceSettlementDiscrepancyDecisionException {
        $runtime = new CommerceSettlementDiscrepancyException(
            systemTotalMinor: 10000,
            settledTotalMinor: 9000,
            observedComponentIds: ['payments.0.amount'],
            componentAnalyses: [],
            missingTransportEvidenceComponentIds: [
                'payments.0.amount',
            ],
        );

        return CommerceSettlementDiscrepancyDecisionException::
            fromInput(
                runtimeEvidence: $runtime,
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

    private function organization(
        string $name
    ): Organization {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function actor(
        Organization $organization
    ): User {
        $user = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

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
