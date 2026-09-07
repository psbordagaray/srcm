<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommercialAvailabilityReader;
use App\Domain\Commerce\InventoryReservationManager;
use App\Domain\Commerce\VariableQuantityFulfillmentManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariableQuantityReservationToleranceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reservation_persists_tolerance_and_holds_maximum_commercial_quantity(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'inventory_reservations',
                [
                    'minimum_fulfillment_quantity',
                    'maximum_fulfillment_quantity',
                ]
            )
        );

        [, $actor, $product, $location] =
            $this->position('5.000000');

        $reservation = app(
            InventoryReservationManager::class
        )->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:hold',
            $actor,
            '0.900',
            '1.100'
        );

        $this->assertSame(
            '0.900000',
            (string) $reservation
                ->minimum_fulfillment_quantity
        );

        $this->assertSame(
            '1.100000',
            (string) $reservation
                ->maximum_fulfillment_quantity
        );

        $this->assertSame(
            '1.100000',
            $reservation->commercialHoldQuantity()
        );

        $position = app(
            CommercialAvailabilityReader::class
        )->positions($actor)->sole();

        $this->assertSame(
            '1.100000',
            $position->reservedQuantity
        );

        $this->assertSame(
            '3.900000',
            $position->commercialAvailableQuantity
        );
    }

    public function test_tolerance_idempotency_is_exact_and_legacy_fingerprint_is_preserved_without_tolerance(): void
    {
        [, $actor, $product, $location] =
            $this->position('5.000000');

        $manager = app(
            InventoryReservationManager::class
        );

        $first = $manager->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:idem',
            $actor,
            '0.900',
            '1.100'
        );

        $retry = $manager->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000000',
            null,
            'variable-quantity:tolerance:idem',
            $actor,
            '0.900000',
            '1.100000'
        );

        $this->assertSame($first->id, $retry->id);

        try {
            $manager->reserve(
                $product->id,
                $location->id,
                InventoryCondition::New,
                '1.000',
                null,
                'variable-quantity:tolerance:idem',
                $actor,
                '0.900',
                '1.120'
            );

            $this->fail(
                'Expected idempotency conflict.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'idempotencia',
                $exception->getMessage()
            );
        }

        $legacy = $manager->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '0.500',
            null,
            'variable-quantity:tolerance:legacy',
            $actor
        );

        $expectedLegacyFingerprint = hash(
            'sha256',
            implode('|', [
                $product->id,
                $location->id,
                InventoryCondition::New->value,
                '0.500000',
                '',
            ])
        );

        $this->assertSame(
            $expectedLegacyFingerprint,
            $legacy->fingerprint
        );
    }

    public function test_tolerance_envelope_must_contain_requested_quantity_and_minimum_needs_maximum(): void
    {
        [, $actor, $product, $location] =
            $this->position('5.000000');

        $manager = app(
            InventoryReservationManager::class
        );

        $invalid = [
            [
                '1.001',
                '1.100',
                'minimo',
                'min-above',
            ],
            [
                '0.900',
                '0.999',
                'maximo',
                'max-below',
            ],
            [
                '0.900',
                null,
                'maximo explicito',
                'min-only',
            ],
        ];

        foreach (
            $invalid as [
                $minimum,
                $maximum,
                $message,
                $suffix,
            ]
        ) {
            try {
                $manager->reserve(
                    $product->id,
                    $location->id,
                    InventoryCondition::New,
                    '1.000',
                    null,
                    'variable-quantity:tolerance:'.$suffix,
                    $actor,
                    $minimum,
                    $maximum
                );

                $this->fail(
                    'Expected tolerance envelope rejection.'
                );
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    $message,
                    strtolower(
                        $exception->getMessage()
                    )
                );
            }
        }

        $this->assertDatabaseCount(
            'inventory_reservations',
            0
        );
    }

    public function test_reservation_rejects_maximum_tolerance_beyond_commercial_capacity(): void
    {
        [, $actor, $product, $location] =
            $this->position('1.050000');

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'tolerancia superan la disponibilidad comercial'
        );

        app(
            InventoryReservationManager::class
        )->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:capacity',
            $actor,
            '0.900',
            '1.100'
        );
    }

    public function test_fulfillment_accepts_inside_and_rejects_outside_reservation_tolerance(): void
    {
        [, $actor, $product, $location] =
            $this->position('5.000000');

        $reservation = app(
            InventoryReservationManager::class
        )->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:reservation',
            $actor,
            '0.900',
            '1.100'
        );

        $accepted = app(
            VariableQuantityFulfillmentManager::class
        )->materialize(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            '1.086',
            '1.086',
            $reservation->id,
            'variable-quantity:tolerance:inside',
            $actor
        );

        $this->assertSame(
            '1.086000',
            (string) $accepted->accepted_quantity
        );

        foreach ([
            ['0.899', 'below'],
            ['1.101', 'above'],
        ] as [$measured, $suffix]) {
            try {
                app(
                    VariableQuantityFulfillmentManager::class
                )->materialize(
                    $product->id,
                    $location->id,
                    InventoryCondition::New,
                    '1.000',
                    $measured,
                    $measured,
                    $reservation->id,
                    'variable-quantity:tolerance:'.$suffix,
                    $actor
                );

                $this->fail(
                    'Expected tolerance rejection.'
                );
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    'tolerancia',
                    strtolower(
                        $exception->getMessage()
                    )
                );
            }
        }

        $this->assertDatabaseCount(
            'variable_quantity_fulfillments',
            1
        );
    }

    public function test_maximum_only_supports_never_exceed_requested_semantics(): void
    {
        [, $actor, $product, $location] =
            $this->position('5.000000');

        $reservation = app(
            InventoryReservationManager::class
        )->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:max-only',
            $actor,
            null,
            '1.000'
        );

        $this->assertTrue(
            $reservation
                ->allowsFulfillmentQuantity('0.950')
        );

        $this->assertTrue(
            $reservation
                ->allowsFulfillmentQuantity('1.000')
        );

        $this->assertFalse(
            $reservation
                ->allowsFulfillmentQuantity('1.001')
        );

        $fulfillment = app(
            VariableQuantityFulfillmentManager::class
        )->materialize(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            '0.950',
            '0.950',
            $reservation->id,
            'variable-quantity:tolerance:max-only:fulfillment',
            $actor
        );

        $this->assertSame(
            '0.950000',
            (string) $fulfillment->accepted_quantity
        );
    }

    public function test_unit_products_cannot_define_variable_quantity_tolerance(): void
    {
        [$organization, $actor, , $location] =
            $this->position('5.000000');

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    [
                        'slug' =>
                            'variable-quantity-tolerance-unit',
                    ],
                    [
                        'name' =>
                            'Variable Quantity Tolerance Unit',
                        'active' => true,
                    ]
                )
        );

        $unitProduct = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()
                ->create([
                    'product_category_id' =>
                        $category->id,
                    'sku' => 'VQT-UNIT',
                    'name' =>
                        'Producto unitario sin tolerancia',
                    'base_unit_code' => 'unit',
                    'quantity_scale' => 0,
                    'active' => true,
                ])
                ->refresh()
        );

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $unitProduct->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '2.000000',
            'base_unit_code' =>
                $unitProduct->base_unit_code,
            'version' => 1,
        ]);

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'producto fraccionable'
        );

        app(
            InventoryReservationManager::class
        )->reserve(
            $unitProduct->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'variable-quantity:tolerance:unit',
            $actor,
            null,
            '1'
        );
    }

    public function test_tolerance_contract_is_immutable_after_reservation_creation(): void
    {
        [, $actor, $product, $location] =
            $this->position('5.000000');

        $reservation = app(
            InventoryReservationManager::class
        )->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1.000',
            null,
            'variable-quantity:tolerance:immutable',
            $actor,
            '0.900',
            '1.100'
        );

        try {
            $reservation
                ->maximum_fulfillment_quantity =
                    '1.200';

            $reservation->save();

            $this->fail(
                'Expected immutable tolerance rejection.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'inmutable',
                strtolower(
                    $exception->getMessage()
                )
            );
        }

        $this->assertSame(
            '1.100000',
            (string) $reservation
                ->fresh()
                ->maximum_fulfillment_quantity
        );
    }

    private function position(
        string $quantity
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $actor = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' =>
                            $organization->id,
                        'user_id' => $actor->id,
                    ],
                    [
                        'role' => UserRole::Operator,
                        'active' => true,
                    ]
                )
        );

        $actor->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    [
                        'slug' =>
                            'variable-quantity-tolerance',
                    ],
                    [
                        'name' =>
                            'Variable Quantity Tolerance',
                        'active' => true,
                    ]
                )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()
                ->create([
                    'product_category_id' =>
                        $category->id,
                    'sku' =>
                        'VQT-'.str_replace(
                            '.',
                            '-',
                            $quantity
                        ),
                    'name' =>
                        'Producto variable tolerancia '
                        .$quantity,
                    'base_unit_code' => 'l',
                    'quantity_scale' => 3,
                    'active' => true,
                ])
                ->refresh()
        );

        $location = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'name' =>
                        'Variable Quantity Tolerance '
                        .$quantity,
                    'type' =>
                        InventoryLocationType::Warehouse,
                    'active' => true,
                ])
                ->refresh()
        );

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => $quantity,
            'base_unit_code' =>
                $product->base_unit_code,
            'version' => 1,
        ]);

        return [
            $organization,
            $actor,
            $product,
            $location,
        ];
    }
}
