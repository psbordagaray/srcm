<?php

namespace App\Providers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Brand;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\InventoryLocation;
use App\Models\Manufacturer;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\TechnicalModel;
use App\Models\User;
use App\Observers\CatalogAuditObserver;
use App\Observers\UserOrganizationObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            CurrentOrganization::class,
            fn () => new CurrentOrganization()
        );
    }

    public function boot(): void
    {
        Brand::observe(CatalogAuditObserver::class);
        BusinessParty::observe(CatalogAuditObserver::class);
        CatalogProduct::observe(CatalogAuditObserver::class);
        Compatibility::observe(CatalogAuditObserver::class);
        Entity::observe(CatalogAuditObserver::class);
        Identifier::observe(CatalogAuditObserver::class);
        InventoryLocation::observe(CatalogAuditObserver::class);
        Manufacturer::observe(CatalogAuditObserver::class);
        Organization::observe(CatalogAuditObserver::class);
        ProductCategory::observe(CatalogAuditObserver::class);
        Supplier::observe(CatalogAuditObserver::class);
        SupplierOffer::observe(CatalogAuditObserver::class);
        TechnicalModel::observe(CatalogAuditObserver::class);

        User::observe(UserOrganizationObserver::class);

        Gate::define(
            'manage-catalog',
            fn (User $user): bool =>
                $user->role->canManageCatalog()
        );

        Gate::define(
            'manage-commerce',
            fn (User $user): bool =>
                app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->canManageCommerce()
                ?? false
        );

        Gate::define(
            'manage-organization',
            fn (User $user): bool =>
                app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->canManageOrganization()
                ?? false
        );

        Gate::define(
            'view-inventory',
            fn (User $user): bool =>
                app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->canViewInventory()
                ?? false
        );

        Gate::define(
            'manage-inventory-locations',
            fn (User $user): bool =>
                app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->canManageInventoryLocations()
                ?? false
        );

        Gate::define(
            'view-audit',
            fn (User $user): bool =>
                app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->canViewAudit()
                ?? false
        );
    }
}
