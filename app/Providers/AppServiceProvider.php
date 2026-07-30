<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\ProductCategory;
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
        Entity::observe(CatalogAuditObserver::class);
        Identifier::observe(CatalogAuditObserver::class);
        ProductCategory::observe(CatalogAuditObserver::class);
        TechnicalModel::observe(CatalogAuditObserver::class);

        Gate::define(
            'manage-catalog',
            fn (User $user): bool => $user->role->canManageCatalog()
        );

        Gate::define(
            'view-audit',
            fn (User $user): bool => $user->role->canViewAudit()
        );
    }
}
