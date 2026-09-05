<?php
namespace Tests\Feature\Commerce;
use App\Domain\Commerce\CommercialAvailabilityReader;
use App\Domain\Commerce\InventoryReservationManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryReservationStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
class InventoryReservationFoundationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }
    public function test_active_reservation_reduces_commercial_availability_without_mutating_physical_stock(): void
    {
        $this->assertTrue(Schema::hasColumns('inventory_reservations', [
            'organization_id','public_id','catalog_product_id','inventory_location_id','condition','quantity',
            'base_unit_code','status','expires_at','released_at','idempotency_key','fingerprint',
        ]));
        [$organization,$actor,$product,$location] = $this->position('4.000000');
        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,$location->id,InventoryCondition::New,'1.500',null,
            'reservation:foundation:active',$actor
        );
        $position = app(CommercialAvailabilityReader::class)->positions($actor)->sole();
        $this->assertSame(InventoryReservationStatus::Active, $reservation->status);
        $this->assertSame('4.000000', $position->physicalAvailableQuantity);
        $this->assertSame('1.500000', $position->reservedQuantity);
        $this->assertSame('2.500000', $position->commercialAvailableQuantity);
        $this->assertSame('4.000000', (string) $this->balance($organization,$product,$location)->quantity);
    }
    public function test_over_reservation_is_rejected_and_idempotency_is_exact(): void
    {
        [, $actor,$product,$location] = $this->position('2.000000');
        $manager = app(InventoryReservationManager::class);
        $first = $manager->reserve($product->id,$location->id,InventoryCondition::New,'1.500',null,'reservation:foundation:idem',$actor);
        $retry = $manager->reserve($product->id,$location->id,InventoryCondition::New,'1.500',null,'reservation:foundation:idem',$actor);
        $this->assertSame($first->id, $retry->id);
        try {
            $manager->reserve($product->id,$location->id,InventoryCondition::New,'1.000',null,'reservation:foundation:overflow',$actor);
            $this->fail('Expected over reservation rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('disponibilidad comercial', $exception->getMessage());
        }
        $this->assertDatabaseCount('inventory_reservations', 1);
    }
    public function test_release_and_expiry_restore_commercial_availability(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');
        $manager = app(InventoryReservationManager::class);
        $reservation = $manager->reserve($product->id,$location->id,InventoryCondition::New,'1.000',null,'reservation:foundation:release',$actor);
        $released = $manager->release($reservation,'Cliente cancelo.',$actor);
        $this->assertSame(InventoryReservationStatus::Released, $released->status);
        $this->assertSame('3.000000', app(CommercialAvailabilityReader::class)->positions($actor)->sole()->commercialAvailableQuantity);
        InventoryReservation::query()->create([
            'organization_id'=>$actor->current_organization_id,'public_id'=>(string) Str::uuid(),
            'catalog_product_id'=>$product->id,'inventory_location_id'=>$location->id,
            'condition'=>InventoryCondition::New,'quantity'=>'2.000000','base_unit_code'=>$product->base_unit_code,
            'status'=>InventoryReservationStatus::Active,'expires_at'=>CarbonImmutable::now()->subMinute(),
            'released_at'=>null,'release_reason'=>null,'created_by_user_id'=>$actor->id,'released_by_user_id'=>null,
            'idempotency_key'=>'reservation:foundation:expired','fingerprint'=>str_repeat('a',64),
        ]);
        $position = app(CommercialAvailabilityReader::class)->positions($actor)->sole();
        $this->assertSame('0.000000', $position->reservedQuantity);
        $this->assertSame('3.000000', $position->commercialAvailableQuantity);
    }
    public function test_reservation_quantity_respects_product_scale(): void
    {
        [, $actor,$product,$location] = $this->position('3.000000');
        $this->expectException(DomainException::class);
        app(InventoryReservationManager::class)->reserve(
            $product->id,$location->id,InventoryCondition::New,'1.0001',null,
            'reservation:foundation:scale',$actor
        );
    }
    private function position(string $quantity): array
    {
        $organization = Organization::query()->where('slug','sulu-tv')->firstOrFail();
        $actor = User::factory()->create(['role'=>UserRole::Operator,'email_verified_at'=>now()]);
        OrganizationMembership::withoutEvents(fn () => OrganizationMembership::query()->updateOrCreate(
            ['organization_id'=>$organization->id,'user_id'=>$actor->id],['role'=>UserRole::Operator,'active'=>true]
        ));
        $actor->forceFill(['current_organization_id'=>$organization->id])->saveQuietly();
        $category = ProductCategory::withoutEvents(fn () => ProductCategory::query()->firstOrCreate(
            ['slug'=>'inventory-reservation'],['name'=>'Inventory Reservation','active'=>true]
        ));
        $product = CatalogProduct::withoutEvents(fn () => CatalogProduct::query()->create([
            'product_category_id'=>$category->id,'sku'=>'RES-'.str_replace('.','-',$quantity),
            'name'=>'Producto reservado '.$quantity,'base_unit_code'=>'l','quantity_scale'=>3,'active'=>true,
        ])->refresh());
        $location = InventoryLocation::withoutEvents(fn () => InventoryLocation::query()->create([
            'organization_id'=>$organization->id,'name'=>'Reserva '.$quantity,
            'type'=>InventoryLocationType::Warehouse,'active'=>true,
        ])->refresh());
        InventoryBalance::query()->create([
            'organization_id'=>$organization->id,'catalog_product_id'=>$product->id,
            'inventory_location_id'=>$location->id,'condition'=>InventoryCondition::New,
            'quantity'=>$quantity,'base_unit_code'=>$product->base_unit_code,'version'=>1,
        ]);
        return [$organization,$actor,$product,$location];
    }
    private function balance(Organization $organization, CatalogProduct $product, InventoryLocation $location): InventoryBalance
    {
        return InventoryBalance::query()->where('organization_id',$organization->id)
            ->where('catalog_product_id',$product->id)->where('inventory_location_id',$location->id)
            ->where('condition',InventoryCondition::New->value)->firstOrFail();
    }
}