<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommercialAvailabilityReader;
use App\Domain\Commerce\InventoryReservationManager;
use App\Domain\Commerce\VariableQuantityFulfillmentManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryReservationStatus;
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

class VariableQuantityFulfillmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_records_intent_measurement_and_acceptance_without_mutating_stock_or_reservation(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'variable_quantity_fulfillments',
            [
                'organization_id','public_id','inventory_reservation_id',
                'catalog_product_id','inventory_location_id','condition',
                'requested_quantity','measured_quantity','accepted_quantity',
                'base_unit_code','created_by_user_id','idempotency_key','fingerprint',
            ]
        ));

        [$organization,$actor,$product,$location] = $this->position('4.000000');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,$location->id,InventoryCondition::New,'1.000',null,
            'variable-quantity:reservation:linked',$actor
        );

        $fulfillment = app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1.000','1.086','1.086',$reservation->id,
            'variable-quantity:fulfillment:linked',$actor
        );

        $this->assertSame($reservation->id, $fulfillment->inventory_reservation_id);
        $this->assertSame('1.000000', (string) $fulfillment->requested_quantity);
        $this->assertSame('1.086000', (string) $fulfillment->measured_quantity);
        $this->assertSame('1.086000', (string) $fulfillment->accepted_quantity);
        $this->assertTrue($fulfillment->isVariable());

        $position = app(CommercialAvailabilityReader::class)->positions($actor)->sole();

        $this->assertSame('4.000000', $position->physicalAvailableQuantity);
        $this->assertSame('1.000000', $position->reservedQuantity);
        $this->assertSame('3.000000', $position->commercialAvailableQuantity);
        $this->assertSame(
            '4.000000',
            (string) $this->balance($organization,$product,$location)->quantity
        );
        $this->assertSame(
            InventoryReservationStatus::Active,
            $reservation->fresh()->status
        );
    }

    public function test_idempotency_is_exact_and_conflicting_replay_fails_closed(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');
        $manager = app(VariableQuantityFulfillmentManager::class);

        $first = $manager->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1.000','1.075','1.075',null,'variable-quantity:idem',$actor
        );

        $retry = $manager->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1.000000','1.075000','1.075000',null,'variable-quantity:idem',$actor
        );

        $this->assertSame($first->id, $retry->id);

        try {
            $manager->materialize(
                $product->id,$location->id,InventoryCondition::New,
                '1.000','1.090','1.090',null,'variable-quantity:idem',$actor
            );
            $this->fail('Expected conflicting replay rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('idempotencia', $exception->getMessage());
        }

        $this->assertDatabaseCount('variable_quantity_fulfillments', 1);
    }

    public function test_linked_reservation_must_remain_effective(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');
        $reservations = app(InventoryReservationManager::class);

        $reservation = $reservations->reserve(
            $product->id,$location->id,InventoryCondition::New,'1.000',null,
            'variable-quantity:reservation:released',$actor
        );
        $reservations->release($reservation, 'Cliente canceló.', $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('reserva vinculada');

        app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1.000','1.050','1.050',$reservation->id,
            'variable-quantity:released',$actor
        );
    }

    public function test_linked_reservation_must_match_reserved_intent_exactly(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,$location->id,InventoryCondition::New,'1.250',null,
            'variable-quantity:reservation:intent',$actor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('intención reservada');

        app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1.000','1.050','1.050',$reservation->id,
            'variable-quantity:intent-mismatch',$actor
        );
    }

    public function test_measurement_acceptance_and_product_precision_fail_closed(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');
        $manager = app(VariableQuantityFulfillmentManager::class);

        try {
            $manager->materialize(
                $product->id,$location->id,InventoryCondition::New,
                '1.000','1.050','1.040',null,
                'variable-quantity:measurement-mismatch',$actor
            );
            $this->fail('Expected measurement mismatch rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('medición física', $exception->getMessage());
        }

        try {
            $manager->materialize(
                $product->id,$location->id,InventoryCondition::New,
                '1.0001','1.050','1.050',null,
                'variable-quantity:precision',$actor
            );
            $this->fail('Expected precision rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('precisión', $exception->getMessage());
        }

        $this->assertDatabaseCount('variable_quantity_fulfillments', 0);
    }

    public function test_standalone_evidence_is_supported_without_reservation(): void
    {
        [, $actor,$product,$location] = $this->position('2.000000');

        $fulfillment = app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '0.500','0.530','0.530',null,
            'variable-quantity:standalone',$actor
        );

        $this->assertNull($fulfillment->inventory_reservation_id);
        $this->assertTrue($fulfillment->isVariable());
    }

    public function test_unit_products_are_not_eligible_for_variable_quantity_fulfillment(): void
    {
        [$organization,$actor] = $this->position('2.000000');

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug'=>'variable-quantity-unit'],
                ['name'=>'Variable Quantity Unit','active'=>true]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id'=>$category->id,'sku'=>'VQ-UNIT',
                'name'=>'Producto unitario','base_unit_code'=>'unit',
                'quantity_scale'=>0,'active'=>true,
            ])->refresh()
        );

        $location = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id'=>$organization->id,
                'name'=>'Variable Quantity Unit',
                'type'=>InventoryLocationType::Warehouse,'active'=>true,
            ])->refresh()
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('producto fraccionable');

        app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '1','1','1',null,'variable-quantity:unit-product',$actor
        );
    }

    public function test_fulfillment_evidence_is_immutable_and_cannot_be_deleted(): void
    {
        [, $actor,$product,$location] = $this->position('2.000000');

        $fulfillment = app(VariableQuantityFulfillmentManager::class)->materialize(
            $product->id,$location->id,InventoryCondition::New,
            '0.500','0.540','0.540',null,
            'variable-quantity:immutable',$actor
        );

        try {
            $fulfillment->accepted_quantity = '0.550';
            $fulfillment->save();
            $this->fail('Expected immutable update rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inmutable', $exception->getMessage());
        }

        $fulfillment->refresh();

        try {
            $fulfillment->delete();
            $this->fail('Expected physical delete rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no se elimina', $exception->getMessage());
        }

        $this->assertDatabaseHas(
            'variable_quantity_fulfillments',
            ['id'=>$fulfillment->id]
        );
    }

    private function position(string $quantity): array
    {
        $organization = Organization::query()->where('slug','sulu-tv')->firstOrFail();

        $actor = User::factory()->create([
            'role'=>UserRole::Operator,
            'email_verified_at'=>now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                ['organization_id'=>$organization->id,'user_id'=>$actor->id],
                ['role'=>UserRole::Operator,'active'=>true]
            )
        );

        $actor->forceFill([
            'current_organization_id'=>$organization->id,
        ])->saveQuietly();

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug'=>'variable-quantity-fulfillment'],
                ['name'=>'Variable Quantity Fulfillment','active'=>true]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id'=>$category->id,
                'sku'=>'VQF-'.str_replace('.','-',$quantity),
                'name'=>'Producto variable '.$quantity,
                'base_unit_code'=>'l','quantity_scale'=>3,'active'=>true,
            ])->refresh()
        );

        $location = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id'=>$organization->id,
                'name'=>'Variable Quantity '.$quantity,
                'type'=>InventoryLocationType::Warehouse,'active'=>true,
            ])->refresh()
        );

        InventoryBalance::query()->create([
            'organization_id'=>$organization->id,
            'catalog_product_id'=>$product->id,
            'inventory_location_id'=>$location->id,
            'condition'=>InventoryCondition::New,
            'quantity'=>$quantity,
            'base_unit_code'=>$product->base_unit_code,
            'version'=>1,
        ]);

        return [$organization,$actor,$product,$location];
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location
    ): InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id',$organization->id)
            ->where('catalog_product_id',$product->id)
            ->where('inventory_location_id',$location->id)
            ->where('condition',InventoryCondition::New->value)
            ->firstOrFail();
    }
}
