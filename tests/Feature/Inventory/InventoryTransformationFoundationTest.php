<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryTransformationManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTransformationDirection;
use App\Enums\InventoryTransformationOutputRole;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransformation;
use App\Models\InventoryTransformationLineage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryTransformationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_records_exact_confirmed_lineage_without_becoming_a_second_stock_authority(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'inventory_transformations',
            [
                'organization_id',
                'public_id',
                'effective_at',
                'created_by_user_id',
                'idempotency_key',
                'fingerprint',
            ]
        ));
        $this->assertTrue(Schema::hasColumns(
            'inventory_transformation_lineages',
            [
                'organization_id',
                'inventory_transformation_id',
                'inventory_movement_line_id',
                'direction',
                'output_role',
                'sequence',
            ]
        ));

        $context = $this->context('transform-main', 'kg', 'kg');
        [$input, $output] = $this->confirmedPair(
            $context,
            '4.000',
            '3.000',
            'transform-main'
        );

        $rawBefore = $this->balance(
            $context['organization'],
            $context['input_product'],
            $context['input_location']
        );
        $outputBefore = $this->balance(
            $context['organization'],
            $context['output_product'],
            $context['output_location']
        );

        $effectiveAt = now();

        $transformation = app(
            InventoryTransformationManager::class
        )->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            $effectiveAt,
            'transform-main:evidence',
            $context['actor']
        );

        $this->assertCount(2, $transformation->lineages);
        $this->assertSame(
            InventoryTransformationDirection::Input,
            $transformation->lineages->first()->direction
        );
        $this->assertSame(
            InventoryTransformationOutputRole::Primary,
            $transformation->lineages->last()->output_role
        );

        $summary = $transformation->quantitySummary();

        $this->assertTrue($summary['supported']);
        $this->assertSame('kg', $summary['base_unit_code']);
        $this->assertSame('4.000000', $summary['input_total']);
        $this->assertSame('3.000000', $summary['output_total']);
        $this->assertSame('0.750000', $summary['yield_ratio']);
        $this->assertSame('1.000000', $summary['residual_quantity']);

        $this->assertSame(
            (string) $rawBefore->quantity,
            (string) $this->balance(
                $context['organization'],
                $context['input_product'],
                $context['input_location']
            )->quantity
        );
        $this->assertSame(
            (string) $outputBefore->quantity,
            (string) $this->balance(
                $context['organization'],
                $context['output_product'],
                $context['output_location']
            )->quantity
        );

        $this->assertSame('6.000000', (string) $rawBefore->quantity);
        $this->assertSame('3.000000', (string) $outputBefore->quantity);
    }

    public function test_idempotency_is_exact_and_conflicting_lineage_fails_closed(): void
    {
        $context = $this->context('transform-idem', 'kg', 'kg');
        [$input, $output] = $this->confirmedPair(
            $context,
            '2.000',
            '1.500',
            'transform-idem'
        );

        $manager = app(InventoryTransformationManager::class);
        $effectiveAt = now();

        $first = $manager->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            $effectiveAt,
            'transform-idem:evidence',
            $context['actor']
        );

        $retry = $manager->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => 'primary',
            ]],
            $effectiveAt,
            'transform-idem:evidence',
            $context['actor']
        );

        $this->assertSame($first->id, $retry->id);

        try {
            $manager->record(
                [$input->lines->sole()->id],
                [[
                    'movement_line_id' => $output->lines->sole()->id,
                    'role' => InventoryTransformationOutputRole::Byproduct,
                ]],
                $effectiveAt,
                'transform-idem:evidence',
                $context['actor']
            );
            $this->fail('Expected conflicting idempotency replay rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'idempotencia',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('inventory_transformations', 1);
        $this->assertDatabaseCount('inventory_transformation_lineages', 2);
    }

    public function test_draft_wrong_direction_duplicate_and_missing_sides_fail_closed(): void
    {
        $context = $this->context('transform-guards', 'kg', 'kg');
        [$input, $output] = $this->confirmedPair(
            $context,
            '1.000',
            '0.800',
            'transform-guards'
        );

        $draft = $this->movement(
            InventoryMovementType::TransformationInput,
            $context['input_product'],
            '0.500',
            $context['input_location'],
            null,
            'transform-guards:draft',
            $context['actor'],
            false
        );

        $manager = app(InventoryTransformationManager::class);

        try {
            $manager->record(
                [$draft->lines->sole()->id],
                [[
                    'movement_line_id' => $output->lines->sole()->id,
                    'role' => InventoryTransformationOutputRole::Primary,
                ]],
                now(),
                'transform-guards:draft-evidence',
                $context['actor']
            );
            $this->fail('Expected draft input rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'confirmada',
                $exception->getMessage()
            );
        }

        try {
            $manager->record(
                [$output->lines->sole()->id],
                [[
                    'movement_line_id' => $input->lines->sole()->id,
                    'role' => InventoryTransformationOutputRole::Primary,
                ]],
                now(),
                'transform-guards:wrong-direction',
                $context['actor']
            );
            $this->fail('Expected direction rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'entrada',
                mb_strtolower($exception->getMessage())
            );
        }

        foreach (
            [
                [
                    [$input->lines->sole()->id, $input->lines->sole()->id],
                    [[
                        'movement_line_id' => $output->lines->sole()->id,
                        'role' => InventoryTransformationOutputRole::Primary,
                    ]],
                    'transform-guards:duplicate-input',
                ],
                [
                    [],
                    [[
                        'movement_line_id' => $output->lines->sole()->id,
                        'role' => InventoryTransformationOutputRole::Primary,
                    ]],
                    'transform-guards:no-input',
                ],
                [
                    [$input->lines->sole()->id],
                    [],
                    'transform-guards:no-output',
                ],
            ] as [$inputs, $outputs, $key]
        ) {
            try {
                $manager->record(
                    $inputs,
                    $outputs,
                    now(),
                    $key,
                    $context['actor']
                );
                $this->fail('Expected structural transformation rejection.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cross_organization_lineage_is_rejected(): void
    {
        $context = $this->context('transform-cross-a', 'kg', 'kg');
        [$input] = $this->confirmedPair(
            $context,
            '1.000',
            '0.700',
            'transform-cross-a'
        );

        $otherOrganization = Organization::query()->create([
            'name' => 'Transform Other Organization',
            'slug' => 'transform-other-organization',
            'active' => true,
        ]);

        $otherActor = $this->actor(
            $otherOrganization,
            UserRole::Operator
        );
        $otherContext = $this->contextForOrganization(
            $otherOrganization,
            $otherActor,
            'transform-cross-b',
            'kg',
            'kg'
        );
        [, $otherOutput] = $this->confirmedPair(
            $otherContext,
            '1.000',
            '0.600',
            'transform-cross-b'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('organización activa');

        app(InventoryTransformationManager::class)->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $otherOutput->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            now(),
            'transform-cross:evidence',
            $context['actor']
        );
    }

    public function test_incompatible_base_units_keep_lineage_but_do_not_invent_yield(): void
    {
        $context = $this->context(
            'transform-units',
            'kg',
            'unit',
            3,
            0
        );
        [$input, $output] = $this->confirmedPair(
            $context,
            '1.000',
            '1',
            'transform-units'
        );

        $transformation = app(
            InventoryTransformationManager::class
        )->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            now(),
            'transform-units:evidence',
            $context['actor']
        );

        $summary = $transformation->quantitySummary();

        $this->assertFalse($summary['supported']);
        $this->assertNull($summary['base_unit_code']);
        $this->assertNull($summary['yield_ratio']);
        $this->assertCount(2, $transformation->lineages);
    }

    public function test_evidence_and_lineage_are_immutable_and_non_deletable(): void
    {
        $context = $this->context('transform-immutable', 'kg', 'kg');
        [$input, $output] = $this->confirmedPair(
            $context,
            '1.000',
            '0.900',
            'transform-immutable'
        );

        $transformation = app(
            InventoryTransformationManager::class
        )->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            now(),
            'transform-immutable:evidence',
            $context['actor']
        );

        try {
            $transformation->idempotency_key = 'mutated';
            $transformation->save();
            $this->fail('Expected immutable transformation rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'inmutable',
                $exception->getMessage()
            );
        }

        $lineage = $transformation->lineages->first();

        try {
            $lineage->sequence = 99;
            $lineage->save();
            $this->fail('Expected immutable lineage rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'inmutable',
                $exception->getMessage()
            );
        }

        foreach ([$lineage, $transformation] as $model) {
            try {
                $model->delete();
                $this->fail('Expected physical delete rejection.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    'no se elimina',
                    $exception->getMessage()
                );
            }
        }

        $this->assertDatabaseHas(
            'inventory_transformations',
            ['id' => $transformation->id]
        );
    }

    public function test_viewer_cannot_record_transformation_evidence(): void
    {
        $context = $this->context('transform-role', 'kg', 'kg');
        [$input, $output] = $this->confirmedPair(
            $context,
            '1.000',
            '0.900',
            'transform-role'
        );
        $viewer = $this->actor(
            $context['organization'],
            UserRole::Viewer
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('rol del usuario');

        app(InventoryTransformationManager::class)->record(
            [$input->lines->sole()->id],
            [[
                'movement_line_id' => $output->lines->sole()->id,
                'role' => InventoryTransformationOutputRole::Primary,
            ]],
            now(),
            'transform-role:evidence',
            $viewer
        );
    }

    private function context(
        string $key,
        string $inputUnit,
        string $outputUnit,
        int $inputScale = 3,
        int $outputScale = 3
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->actor(
            $organization,
            UserRole::Operator
        );

        return $this->contextForOrganization(
            $organization,
            $actor,
            $key,
            $inputUnit,
            $outputUnit,
            $inputScale,
            $outputScale
        );
    }

    private function contextForOrganization(
        Organization $organization,
        User $actor,
        string $key,
        string $inputUnit,
        string $outputUnit,
        int $inputScale = 3,
        int $outputScale = 3
    ): array {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-transformation-foundation'],
                [
                    'name' => 'Inventory Transformation Foundation',
                    'active' => true,
                ]
            )
        );

        $inputProduct = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => strtoupper($key).'-IN',
                'name' => 'Transformation Input '.$key,
                'base_unit_code' => $inputUnit,
                'quantity_scale' => $inputScale,
                'active' => true,
            ])->refresh()
        );

        $outputProduct = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => strtoupper($key).'-OUT',
                'name' => 'Transformation Output '.$key,
                'base_unit_code' => $outputUnit,
                'quantity_scale' => $outputScale,
                'active' => true,
            ])->refresh()
        );

        $inputLocation = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Transformation Input '.$key,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])->refresh()
        );

        $outputLocation = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Transformation Output '.$key,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])->refresh()
        );

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $inputProduct->id,
            'inventory_location_id' => $inputLocation->id,
            'condition' => InventoryCondition::New,
            'quantity' => '10.000000',
            'base_unit_code' => $inputProduct->base_unit_code,
            'version' => 1,
        ]);

        return [
            'organization' => $organization,
            'actor' => $actor,
            'input_product' => $inputProduct,
            'output_product' => $outputProduct,
            'input_location' => $inputLocation,
            'output_location' => $outputLocation,
        ];
    }

    private function actor(
        Organization $organization,
        UserRole $role
    ): User {
        $actor = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $actor->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        $actor->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $actor;
    }

    private function confirmedPair(
        array $context,
        string $inputQuantity,
        string $outputQuantity,
        string $key
    ): array {
        $input = $this->movement(
            InventoryMovementType::TransformationInput,
            $context['input_product'],
            $inputQuantity,
            $context['input_location'],
            null,
            $key.':input',
            $context['actor'],
            true
        );

        $output = $this->movement(
            InventoryMovementType::TransformationOutput,
            $context['output_product'],
            $outputQuantity,
            null,
            $context['output_location'],
            $key.':output',
            $context['actor'],
            true
        );

        return [$input, $output];
    }

    private function movement(
        InventoryMovementType $type,
        CatalogProduct $product,
        string $quantity,
        ?InventoryLocation $source,
        ?InventoryLocation $destination,
        string $key,
        User $actor,
        bool $confirm
    ): InventoryMovement {
        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: $type,
                effectiveAt: now(),
                reason: 'Inventory transformation foundation',
                idempotencyKey: $key,
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: $quantity,
                        enteredUnitCode: $product->base_unit_code,
                        conversionFactor: '1',
                        sourceLocationId: $source?->id,
                        destinationLocationId: $destination?->id
                    ),
                ]
            ),
            $actor
        );

        return $confirm
            ? app(InventoryMovementConfirmer::class)->confirm(
                $movement,
                $actor
            )
            : $movement->load('lines');
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location
    ): InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id', $organization->id)
            ->where('catalog_product_id', $product->id)
            ->where('inventory_location_id', $location->id)
            ->where('condition', InventoryCondition::New->value)
            ->firstOrFail();
    }
}
