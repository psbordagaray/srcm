<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionException;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementReviewRecorder;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Release\ReleasePreflightInspector;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CatalogProduct;
use App\Models\CommerceSettlementReview;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceSettlementReviewRuntimePersistenceWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_keep_reference_http_mismatch_persists_review_after_manager_rollback_and_preserves_hard_fail(): void
    {
        $fixture = $this->saleFixture('runtime-review-001');
        $payload = $this->mismatchPayload(
            $fixture,
            'Preserve system total and require settlement review.'
        );

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors([
                'commerce' =>
                    CommerceSettlementDiscrepancyException::MESSAGE,
            ]);

        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertDatabaseCount('commerce_payments', 0);
        $this->assertDatabaseCount('commerce_settlement_reviews', 1);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewRecorder::AUDIT_EVENT
                )
                ->count()
        );

        $review = CommerceSettlementReview::query()->sole();

        $this->assertSame(
            $fixture['organization']->id,
            $review->organization_id
        );
        $this->assertSame(
            $fixture['idempotency_key'],
            $review->checkout_idempotency_key
        );
        $this->assertSame(100000, $review->system_total_minor);
        $this->assertSame(90000, $review->settled_total_minor);
        $this->assertSame('KEEP_REFERENCE', $review->decision);
        $this->assertSame(100000, $review->final_value_minor);
        $this->assertSame(
            'Preserve system total and require settlement review.',
            $review->reason
        );
        $this->assertSame(
            $fixture['actor']->id,
            $review->requested_by_user_id
        );
    }

    public function test_identical_retry_is_idempotent_and_changed_reason_fails_closed_without_duplicate(): void
    {
        $fixture = $this->saleFixture('runtime-review-002');
        $payload = $this->mismatchPayload(
            $fixture,
            'Preserve exact system total.'
        );

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $payload)
            ->assertSessionHasErrors([
                'commerce' =>
                    CommerceSettlementDiscrepancyException::MESSAGE,
            ]);

        $first = CommerceSettlementReview::query()->sole();

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $payload)
            ->assertSessionHasErrors([
                'commerce' =>
                    CommerceSettlementDiscrepancyException::MESSAGE,
            ]);

        $same = CommerceSettlementReview::query()->sole();

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('commerce_settlement_reviews', 1);
        $this->assertDatabaseCount('commerce_sales', 0);

        $conflict = $payload;
        $conflict['settlement_discrepancy_reason'] =
            'Different explicit review reason.';

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $conflict)
            ->assertSessionHasErrors([
                'commerce' =>
                    'La liquidación ya posee otra revisión para la misma clave de checkout.',
            ]);

        $this->assertDatabaseCount('commerce_settlement_reviews', 1);
        $this->assertDatabaseCount('commerce_sales', 0);
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

    public function test_mismatch_without_decision_does_not_persist_review_and_balanced_checkout_does_not_create_review(): void
    {
        $fixture = $this->saleFixture('runtime-review-003');

        $mismatch = $this->basePayload($fixture);
        $mismatch['payments'][0]['amount'] = '900,00';

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $mismatch)
            ->assertSessionHasErrors([
                'commerce' =>
                    CommerceSettlementDiscrepancyException::MESSAGE,
            ]);

        $this->assertDatabaseCount('commerce_settlement_reviews', 0);
        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where(
                    'event',
                    CommerceSettlementReviewRecorder::AUDIT_EVENT
                )
                ->count()
        );

        $balanced = $this->basePayload($fixture);
        $balanced['idempotency_key'] =
            'service-ui:commerce-sale:'.Str::uuid();

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), $balanced)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('commerce_settlement_reviews', 0);
        $this->assertDatabaseCount('commerce_sales', 1);
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

    public function test_controller_metadata_preflight_and_manager_boundary_are_coherent(): void
    {
        $policy = config(
            'release.numeric_integrity.discrepancy_framework'
        );

        $this->assertIsArray($policy);
        $this->assertTrue(
            $policy[
                'commerce_settlement_aggregate_decision_controller_runtime_wired'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_decision_runtime_controller_special_handling'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewRecorder::FOUNDATION_VERSION,
            $policy[
                'commerce_settlement_review_persistence_foundation_version'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewRecorder::class,
            $policy[
                'commerce_settlement_review_persistence_recorder_class'
            ]
        );
        $this->assertSame(
            CommerceSettlementReview::class,
            $policy[
                'commerce_settlement_review_persistence_model_class'
            ]
        );
        $this->assertSame(
            CommerceSettlementReviewRecorder::RUNTIME_WIRING_STATUS,
            $policy[
                'commerce_settlement_review_persistence_runtime_status'
            ]
        );
        $this->assertSame(
            'controller_post_manager_rollback_dedicated_decision_exception_catch',
            $policy[
                'commerce_settlement_review_persistence_boundary'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_checkout_idempotent'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_conflict_fails_closed'
            ]
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_review_persistence_requires_sale'
            ]
        );
        $this->assertTrue(
            $policy[
                'commerce_settlement_review_persistence_hard_fail_preserved'
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

        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($manager);
        $this->assertIsString($controller);
        $this->assertStringNotContainsString(
            'CommerceSettlementReviewRecorder',
            $manager
        );
        $this->assertStringContainsString(
            'CommerceSettlementReviewRecorder',
            $controller
        );
        $this->assertStringContainsString(
            'catch (CommerceSettlementDiscrepancyDecisionException $exception)',
            $controller
        );
        $this->assertStringContainsString(
            'catch (DomainException $exception)',
            $controller
        );

        $decision = CommerceSettlementDiscrepancyDecisionEvidence::
            keepReference(
                runtimeEvidence:
                    new CommerceSettlementDiscrepancyException(
                        systemTotalMinor: 100000,
                        settledTotalMinor: 90000,
                        observedComponentIds: ['payments.0.amount'],
                        componentAnalyses: [],
                        missingTransportEvidenceComponentIds: [
                            'payments.0.amount',
                        ],
                    ),
                reason: 'Metadata coherence.',
            )->toArray();

        $this->assertTrue($decision['controller_runtime_wired']);

        $wrapped =
            CommerceSettlementDiscrepancyDecisionException::fromInput(
                runtimeEvidence:
                    new CommerceSettlementDiscrepancyException(
                        systemTotalMinor: 100000,
                        settledTotalMinor: 90000,
                        observedComponentIds: ['payments.0.amount'],
                        componentAnalyses: [],
                        missingTransportEvidenceComponentIds: [
                            'payments.0.amount',
                        ],
                    ),
                input:
                    CommerceSettlementDiscrepancyDecisionInput::
                        keepReference('Metadata wrapper coherence.'),
            )->toArray();

        $this->assertTrue($wrapped['controller_special_handling']);

        $preflight = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $preflight['static']['p13_numeric_integrity_policy_contract']
        );
        $this->assertFalse($preflight['production_authorized']);
    }

    /**
     * @return array{
     *   organization: Organization,
     *   actor: User,
     *   location: InventoryLocation,
     *   product: CatalogProduct,
     *   account: FinancialAccount,
     *   idempotency_key: string
     * }
     */
    private function saleFixture(string $suffix): array
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $location = $this->location($organization);
        $product = $this->product(
            'Runtime review '.$suffix,
            'RUNTIME-REVIEW-'.Str::upper(Str::substr(md5($suffix), 0, 8))
        );

        $this->seedStock(
            $actor,
            $product,
            $location,
            '5'
        );
        $this->setPrice(
            $organization,
            $actor,
            $product,
            100000
        );

        $account = $this->financialAccount(
            $organization,
            $actor,
            'ARS',
            'Runtime review account '.$suffix
        );

        return [
            'organization' => $organization,
            'actor' => $actor,
            'location' => $location,
            'product' => $product,
            'account' => $account,
            'idempotency_key' =>
                'service-ui:commerce-sale:'.Str::uuid(),
        ];
    }

    /**
     * @param array{
     *   organization: Organization,
     *   actor: User,
     *   location: InventoryLocation,
     *   product: CatalogProduct,
     *   account: FinancialAccount,
     *   idempotency_key: string
     * } $fixture
     * @return array<string, mixed>
     */
    private function basePayload(array $fixture): array
    {
        return [
            'currency_code' => 'ARS',
            'service_order_id' => null,
            'customer_business_party_id' => null,
            'product_lines' => [[
                'catalog_product_id' => $fixture['product']->id,
                'source_location_id' => $fixture['location']->id,
                'condition' => InventoryCondition::New->value,
                'quantity' => '1',
            ]],
            'payments' => [[
                'method' => CommercePaymentMethod::BankTransfer->value,
                'financial_account_id' => $fixture['account']->id,
                'amount' => '1000,00',
                'reference' => 'RUNTIME-REVIEW-TRANSFER',
                'notes' => null,
                'paid_at' => null,
            ]],
            'idempotency_key' => $fixture['idempotency_key'],
        ];
    }

    /**
     * @param array{
     *   organization: Organization,
     *   actor: User,
     *   location: InventoryLocation,
     *   product: CatalogProduct,
     *   account: FinancialAccount,
     *   idempotency_key: string
     * } $fixture
     * @return array<string, mixed>
     */
    private function mismatchPayload(
        array $fixture,
        string $reason
    ): array {
        $payload = $this->basePayload($fixture);
        $payload['payments'][0]['amount'] = '900,00';
        $payload['settlement_discrepancy_decision'] =
            'KEEP_REFERENCE';
        $payload['settlement_discrepancy_reason'] = $reason;

        return $payload;
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
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
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
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

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'runtime-review-tests'],
                [
                    'name' => 'Runtime Review Tests',
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

    private function seedStock(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): void {
        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Runtime review stock fixture.',
                idempotencyKey:
                    'runtime-review:stock:'.$product->id,
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: $quantity,
                        enteredUnitCode: $product->base_unit_code,
                        destinationLocationId: $location->id
                    ),
                ]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );
    }

    private function setPrice(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        int $amountMinor
    ): void {
        OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => $amountMinor,
            'valid_from' => now()->subSecond(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Runtime review test fixture',
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function financialAccount(
        Organization $organization,
        User $creator,
        string $currency,
        string $name
    ): FinancialAccount {
        $normalized = Str::lower(
            preg_replace('/[^a-zA-Z0-9]+/', '', $name)
                ?? $name
        );

        return FinancialAccount::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'normalized_name' =>
                $normalized.'-'.Str::lower(Str::random(6)),
            'type' => FinancialAccountType::Other,
            'currency_code' => $currency,
            'active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);
    }
}
