<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\FulfillmentPreferenceManager;
use App\Domain\Commerce\InventoryReservationManager;
use App\Enums\FulfillmentUnavailableFallback;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryReservationStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FulfillmentPreference;
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

class FulfillmentPreferenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_records_durable_intent_without_mutating_reservation_or_stock(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'fulfillment_preferences',
            [
                'organization_id',
                'public_id',
                'inventory_reservation_id',
                'allow_another_brand',
                'allow_equivalent_product',
                'require_exact_product',
                'consultation_required',
                'consultation_channel',
                'unavailable_line_fallback',
                'created_by_user_id',
                'idempotency_key',
                'fingerprint',
            ]
        ));

        [$organization, $actor, $product, $location] = $this->position('4');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'fulfillment-preference:reservation:base',
            $actor
        );

        $preference = app(FulfillmentPreferenceManager::class)->record(
            $reservation->id,
            true,
            true,
            false,
            true,
            'WhatsApp',
            FulfillmentUnavailableFallback::ConsultCustomer,
            'fulfillment-preference:base',
            $actor
        );

        $this->assertSame($reservation->id, $preference->inventory_reservation_id);
        $this->assertTrue($preference->allow_another_brand);
        $this->assertTrue($preference->allow_equivalent_product);
        $this->assertFalse($preference->require_exact_product);
        $this->assertTrue($preference->consultation_required);
        $this->assertSame('whatsapp', $preference->consultation_channel);
        $this->assertSame(
            FulfillmentUnavailableFallback::ConsultCustomer,
            $preference->unavailable_line_fallback
        );

        $reservation->refresh();
        $this->assertSame(InventoryReservationStatus::Active, $reservation->status);
        $this->assertSame('1.000000', (string) $reservation->quantity);
        $this->assertSame(
            '4.000000',
            (string) $this->balance($organization, $product, $location)->quantity
        );
    }

    public function test_idempotency_is_exact_and_channel_normalization_is_stable(): void
    {
        [, $actor, $product, $location] = $this->position('3');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'fulfillment-preference:reservation:idem',
            $actor
        );

        $manager = app(FulfillmentPreferenceManager::class);

        $first = $manager->record(
            $reservation->id,
            true,
            false,
            false,
            true,
            ' WhatsApp ',
            FulfillmentUnavailableFallback::KeepPending,
            'fulfillment-preference:idem',
            $actor
        );

        $retry = $manager->record(
            $reservation->id,
            true,
            false,
            false,
            true,
            'whatsapp',
            FulfillmentUnavailableFallback::KeepPending,
            'fulfillment-preference:idem',
            $actor
        );

        $this->assertSame($first->id, $retry->id);

        try {
            $manager->record(
                $reservation->id,
                false,
                true,
                false,
                true,
                'whatsapp',
                FulfillmentUnavailableFallback::KeepPending,
                'fulfillment-preference:idem',
                $actor
            );
            $this->fail('Expected conflicting idempotent replay rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('idempotencia', $exception->getMessage());
        }

        $this->assertDatabaseCount('fulfillment_preferences', 1);
    }

    public function test_exact_product_intent_rejects_substitution_permissions(): void
    {
        [, $actor, $product, $location] = $this->position('2');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'fulfillment-preference:reservation:exact',
            $actor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('producto exacto');

        app(FulfillmentPreferenceManager::class)->record(
            $reservation->id,
            true,
            false,
            true,
            false,
            null,
            FulfillmentUnavailableFallback::RemoveLine,
            'fulfillment-preference:exact-conflict',
            $actor
        );
    }

    public function test_customer_consultation_requires_a_channel(): void
    {
        [, $actor, $product, $location] = $this->position('2');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'fulfillment-preference:reservation:consult',
            $actor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('canal de consulta');

        app(FulfillmentPreferenceManager::class)->record(
            $reservation->id,
            false,
            false,
            false,
            false,
            null,
            FulfillmentUnavailableFallback::ConsultCustomer,
            'fulfillment-preference:missing-channel',
            $actor
        );
    }

    public function test_preference_evidence_is_immutable_and_not_physically_deletable(): void
    {
        [, $actor, $product, $location] = $this->position('2');

        $reservation = app(InventoryReservationManager::class)->reserve(
            $product->id,
            $location->id,
            InventoryCondition::New,
            '1',
            null,
            'fulfillment-preference:reservation:immutable',
            $actor
        );

        $preference = app(FulfillmentPreferenceManager::class)->record(
            $reservation->id,
            false,
            false,
            true,
            false,
            null,
            FulfillmentUnavailableFallback::KeepPending,
            'fulfillment-preference:immutable',
            $actor
        );

        $this->assertTrue($preference->forbidsSubstitution());

        try {
            $preference->allow_another_brand = true;
            $preference->save();
            $this->fail('Expected immutable update rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inmutable', $exception->getMessage());
        }

        $preference->refresh();

        try {
            $preference->delete();
            $this->fail('Expected physical delete rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no se elimina', $exception->getMessage());
        }

        $this->assertDatabaseHas(
            'fulfillment_preferences',
            ['id' => $preference->id]
        );
    }

    private function position(string $quantity): array
    {
        $organization = Organization::query()->where('slug', 'sulu-tv')->firstOrFail();

        $actor = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $actor->id],
                ['role' => UserRole::Operator, 'active' => true]
            )
        );

        $actor->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'fulfillment-preference-foundation'],
                ['name' => 'Fulfillment Preference Foundation', 'active' => true]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'FPF-'.str_replace('.', '-', $quantity).'-'.uniqid(),
                'name' => 'Producto preference '.$quantity,
                'base_unit_code' => 'l',
                'quantity_scale' => 3,
                'active' => true,
            ])->refresh()
        );

        $location = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Fulfillment Preference '.$quantity.' '.uniqid(),
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])->refresh()
        );

        InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => $quantity,
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);

        return [$organization, $actor, $product, $location];
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
