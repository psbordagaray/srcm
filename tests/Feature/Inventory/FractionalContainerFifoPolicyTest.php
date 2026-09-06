<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerConsumptionManager;
use App\Domain\Inventory\FractionalContainerManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\FractionalContainerConsumptionPolicy;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FractionalContainerFifoPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_fifo_consumes_oldest_receipt_effective_at_not_lowest_container_id(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-EFFECTIVE');

        $oldLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-01-10T12:00:00Z'
        )[0];

        $newLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-02-10T12:00:00Z'
        )[0];

        $manager = app(FractionalContainerManager::class);

        $newer = $manager->registerFromReceiptLine(
            $newLine->id,
            'FIFO-NEWER-LOW-ID',
            '20'
        );
        $older = $manager->registerFromReceiptLine(
            $oldLine->id,
            'FIFO-OLDER-HIGH-ID',
            '20'
        );

        $this->assertGreaterThan($newer->id, $older->id);

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::Fifo
            );

        $older->refresh();
        $newer->refresh();

        $this->assertSame(
            FractionalContainerState::Open,
            $older->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $older->remaining_base_quantity,
                '15'
            )
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $newer->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $newer->remaining_base_quantity,
                '20'
            )
        );

        $history = $this->history($line);

        $this->assertCount(1, $history);
        $this->assertSame(
            (int) $older->id,
            (int) $history[0]->fractional_container_id
        );
        $this->assertSame(
            FractionalContainerConsumptionPolicy::Fifo->value,
            (string) $history[0]->policy
        );
    }

    public function test_fifo_receipt_chronology_wins_over_newer_open_container(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-OPEN-RULE');

        $oldLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-03-01T10:00:00Z'
        )[0];

        $newLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-04-01T10:00:00Z'
        )[0];

        $containerManager = app(FractionalContainerManager::class);
        $older = $containerManager->registerFromReceiptLine(
            $oldLine->id,
            'FIFO-OPEN-OLDER',
            '20'
        );
        $newer = $containerManager->registerFromReceiptLine(
            $newLine->id,
            'FIFO-OPEN-NEWER',
            '20'
        );

        $warmup = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '2'
        );
        $warmupLine = $warmup->lines->firstOrFail();

        $consumption = app(
            FractionalContainerConsumptionManager::class
        );

        $consumption->confirm(
            $warmup,
            $actor,
            FractionalContainerConsumptionPolicy::ManualSelection,
            [
                $warmupLine->id => [$newer->id],
            ]
        );

        $this->assertSame(
            FractionalContainerState::Open,
            $newer->refresh()->state
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        $consumption->confirm(
            $issue,
            $actor,
            FractionalContainerConsumptionPolicy::Fifo
        );

        $older->refresh();
        $newer->refresh();

        $this->assertSame(
            FractionalContainerState::Open,
            $older->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $older->remaining_base_quantity,
                '15'
            )
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $newer->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $newer->remaining_base_quantity,
                '18'
            )
        );

        $history = $this->history($line);

        $this->assertSame(
            (int) $older->id,
            (int) $history[0]->fractional_container_id
        );
    }

    public function test_fifo_ties_same_effective_at_by_receipt_movement_id_before_container_id(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-MOVEMENT-TIE');

        $effectiveAt = '2026-05-01T08:30:00Z';

        $firstLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            $effectiveAt
        )[0];

        $secondLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            $effectiveAt
        )[0];

        $manager = app(FractionalContainerManager::class);

        $secondMovementContainer =
            $manager->registerFromReceiptLine(
                $secondLine->id,
                'FIFO-MOVEMENT-SECOND-LOW-ID',
                '20'
            );

        $firstMovementContainer =
            $manager->registerFromReceiptLine(
                $firstLine->id,
                'FIFO-MOVEMENT-FIRST-HIGH-ID',
                '20'
            );

        $this->assertGreaterThan(
            $secondMovementContainer->id,
            $firstMovementContainer->id
        );
        $this->assertLessThan(
            $secondLine->inventory_movement_id,
            $firstLine->inventory_movement_id
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::Fifo
            );

        $history = $this->history($line);

        $this->assertSame(
            (int) $firstMovementContainer->id,
            (int) $history[0]->fractional_container_id
        );
    }

    public function test_fifo_ties_same_receipt_movement_by_line_sequence(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-LINE-TIE');

        [$firstLine, $secondLine] =
            $this->confirmedReceiptLines(
                $organization,
                $actor,
                $product,
                $location,
                ['20', '20'],
                '2026-06-01T09:00:00Z'
            );

        $manager = app(FractionalContainerManager::class);

        $secondLineContainer =
            $manager->registerFromReceiptLine(
                $secondLine->id,
                'FIFO-LINE-SECOND-LOW-ID',
                '20'
            );

        $firstLineContainer =
            $manager->registerFromReceiptLine(
                $firstLine->id,
                'FIFO-LINE-FIRST-HIGH-ID',
                '20'
            );

        $this->assertGreaterThan(
            $secondLineContainer->id,
            $firstLineContainer->id
        );
        $this->assertSame(1, (int) $firstLine->sequence);
        $this->assertSame(2, (int) $secondLine->sequence);

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::Fifo
            );

        $history = $this->history($line);

        $this->assertSame(
            (int) $firstLineContainer->id,
            (int) $history[0]->fractional_container_id
        );
    }

    public function test_fifo_ties_same_receipt_line_by_container_id(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-CONTAINER-TIE');

        $receiptLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['40'],
            '2026-07-01T09:00:00Z'
        )[0];

        $manager = app(FractionalContainerManager::class);

        $first = $manager->registerFromReceiptLine(
            $receiptLine->id,
            'FIFO-SAME-LINE-A',
            '20'
        );
        $second = $manager->registerFromReceiptLine(
            $receiptLine->id,
            'FIFO-SAME-LINE-B',
            '20'
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '25'
        );
        $line = $issue->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::Fifo
            );

        $history = $this->history($line);

        $this->assertCount(2, $history);
        $this->assertSame(
            (int) $first->id,
            (int) $history[0]->fractional_container_id
        );
        $this->assertSame(
            (int) $second->id,
            (int) $history[1]->fractional_container_id
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $history[0]->consumed_base_quantity,
                '20'
            )
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $history[1]->consumed_base_quantity,
                '5'
            )
        );
    }

    public function test_fifo_excludes_legacy_null_provenance_and_fails_atomically_when_provenance_capacity_is_insufficient(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-LEGACY');

        $receiptLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['10'],
            '2026-08-01T10:00:00Z'
        )[0];

        $manager = app(FractionalContainerManager::class);

        $provenance = $manager->registerFromReceiptLine(
            $receiptLine->id,
            'FIFO-PROVENANCE-10',
            '10'
        );

        $legacy = $manager->register(
            $organization->id,
            $product->id,
            $location->id,
            'FIFO-LEGACY-20',
            '20'
        );

        $this->assertNull(
            $legacy->received_inventory_movement_line_id
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '15'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::Fifo
            )
        );

        $provenance->refresh();
        $legacy->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $provenance->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $provenance->remaining_base_quantity,
                '10'
            )
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $legacy->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $legacy->remaining_base_quantity,
                '20'
            )
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
    }

    public function test_fifo_replay_is_idempotent_and_policy_mismatch_fails_closed(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-REPLAY');

        $oldLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-09-01T10:00:00Z'
        )[0];

        $newLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-09-02T10:00:00Z'
        )[0];

        $manager = app(FractionalContainerManager::class);
        $old = $manager->registerFromReceiptLine(
            $oldLine->id,
            'FIFO-REPLAY-OLD',
            '20'
        );
        $new = $manager->registerFromReceiptLine(
            $newLine->id,
            'FIFO-REPLAY-NEW',
            '20'
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '25'
        );
        $line = $issue->lines->firstOrFail();

        $consumption = app(
            FractionalContainerConsumptionManager::class
        );

        $first = $consumption->confirm(
            $issue,
            $actor,
            FractionalContainerConsumptionPolicy::Fifo
        );
        $second = $consumption->confirm(
            $issue,
            $actor,
            FractionalContainerConsumptionPolicy::Fifo
        );

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $first->status
        );
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $second->status
        );
        $this->assertCount(2, $this->history($line));

        $old->refresh();
        $new->refresh();

        $this->assertSame(
            FractionalContainerState::Exhausted,
            $old->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $old->remaining_base_quantity,
                '0'
            )
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $new->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $new->remaining_base_quantity,
                '15'
            )
        );

        $this->assertDomainRejected(
            fn () => $consumption->confirm(
                $issue,
                $actor,
                FractionalContainerConsumptionPolicy::ExhaustOpenContainer
            )
        );

        $this->assertCount(2, $this->history($line));
    }

    public function test_generic_confirmer_rejects_forged_fifo_history_that_skips_older_receipt(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-FIFO-DIRECT-BYPASS');

        $oldLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-10-01T10:00:00Z'
        )[0];

        $newLine = $this->confirmedReceiptLines(
            $organization,
            $actor,
            $product,
            $location,
            ['20'],
            '2026-10-02T10:00:00Z'
        )[0];

        $manager = app(FractionalContainerManager::class);

        $older = $manager->registerFromReceiptLine(
            $oldLine->id,
            'FIFO-BYPASS-OLD',
            '20'
        );
        $newer = $manager->registerFromReceiptLine(
            $newLine->id,
            'FIFO-BYPASS-NEW',
            '20'
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        DB::table('fractional_container_consumptions')
            ->insert([
                'organization_id' => $organization->id,
                'inventory_movement_line_id' => $line->id,
                'fractional_container_id' => $newer->id,
                'sequence' => 1,
                'policy' =>
                    FractionalContainerConsumptionPolicy::Fifo->value,
                'consumed_base_quantity' => '5',
                'base_unit_code' => 'l',
                'state_before' =>
                    FractionalContainerState::Sealed->value,
                'state_after' =>
                    FractionalContainerState::Open->value,
                'remaining_before' => '20',
                'remaining_after' => '15',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertDomainRejected(
            fn () => app(
                InventoryMovementConfirmer::class
            )->confirm(
                $issue,
                $actor
            )
        );

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $older->refresh()->state
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $newer->refresh()->state
        );
        $this->assertCount(1, $this->history($line));
    }

    /**
     * @return array{
     *     Organization,
     *     User,
     *     CatalogProduct,
     *     InventoryLocation
     * }
     */
    private function scenario(string $sku): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $actor = $this->actor($organization);
        $product = $this->product($sku);

        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        return [$organization, $actor, $product, $location];
    }

    private function actor(Organization $organization): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => UserRole::Admin->value,
                    'active' => true,
                ]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function product(string $sku): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'fractional-fifo'],
                [
                    'name' => 'Fractional FIFO',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => 'Lubricante '.$sku,
                'base_unit_code' => 'l',
                'quantity_scale' => 3,
                'active' => true,
            ])->refresh()
        );
    }

    /**
     * @param list<string> $quantities
     * @return list<InventoryMovementLine>
     */
    private function confirmedReceiptLines(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        array $quantities,
        string $effectiveAt
    ): array {
        $movement = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Receipt,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => CarbonImmutable::parse(
                $effectiveAt
            ),
            'reason' => 'FIFO receipt test',
            'idempotency_key' =>
                'fifo-receipt:'.Str::uuid(),
        ]);

        $lines = [];

        foreach ($quantities as $index => $quantity) {
            $lines[] = InventoryMovementLine::query()->create([
                'organization_id' => $organization->id,
                'inventory_movement_id' => $movement->id,
                'sequence' => $index + 1,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New,
                'source_location_id' => null,
                'destination_location_id' => $location->id,
                'entered_quantity' => $quantity,
                'entered_unit_code' => 'l',
                'conversion_factor' => '1',
                'base_quantity' => $quantity,
                'base_unit_code' => 'l',
            ]);
        }

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );

        return array_map(
            static fn (
                InventoryMovementLine $line
            ): InventoryMovementLine => $line->refresh(),
            $lines
        );
    }

    private function issue(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryMovement {
        $movement = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Issue,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => now(),
            'reason' => 'FIFO issue test',
            'idempotency_key' =>
                'fifo-issue:'.Str::uuid(),
        ]);

        InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'source_location_id' => $location->id,
            'destination_location_id' => null,
            'entered_quantity' => $quantity,
            'entered_unit_code' => 'l',
            'conversion_factor' => '1',
            'base_quantity' => $quantity,
            'base_unit_code' => 'l',
        ]);

        return $movement->load('lines');
    }

    private function history(
        InventoryMovementLine $line
    ): \Illuminate\Support\Collection {
        return DB::table(
            'fractional_container_consumptions'
        )
            ->where(
                'inventory_movement_line_id',
                $line->id
            )
            ->orderBy('sequence')
            ->get();
    }

    private function assertDomainRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(
            'La operación debía ser rechazada por el dominio.'
        );
    }
}
