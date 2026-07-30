<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\Manufacturer;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TechnicalModel;
use App\Models\User;
use App\Observers\CatalogAuditObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Brand::observe(CatalogAuditObserver::class);
        BusinessParty::observe(CatalogAuditObserver::class);
        CatalogProduct::observe(CatalogAuditObserver::class);
        Compatibility::observe(CatalogAuditObserver::class);
        Entity::observe(CatalogAuditObserver::class);
        Identifier::observe(CatalogAuditObserver::class);
        Manufacturer::observe(CatalogAuditObserver::class);
        ProductCategory::observe(CatalogAuditObserver::class);
        Supplier::observe(CatalogAuditObserver::class);
        TechnicalModel::observe(CatalogAuditObserver::class);

        Gate::define(
            'manage-catalog',
            fn (User $user): bool => $user->role->canManageCatalog()
        );

        Gate::define(
            'manage-commerce',
            fn (User $user): bool => $user->role->canManageCommerce()
        );

        Gate::define(
            'view-audit',
            fn (User $user): bool => $user->role->canViewAudit()
        );
    }
}
