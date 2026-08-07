<?php

namespace App\Providers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Brand;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
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
            fn () => new CurrentOrganization
        );
    }

    public function boot(): void
    {
        Brand::observe(CatalogAuditObserver::class);
        BusinessParty::observe(CatalogAuditObserver::class);
        CatalogProduct::observe(CatalogAuditObserver::class);
        Customer::observe(CatalogAuditObserver::class);
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
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canManageCatalog()
                ?? false
        );

        Gate::define(
            'manage-commerce',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canManageCommerce()
                ?? false
        );

        foreach ([
            'view-business-parties' => 'canViewBusinessParties',
            'manage-business-parties' => 'canManageBusinessParties',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }
        foreach ([
            'view-customers' => 'canViewCustomers',
            'manage-customers' => 'canManageCustomers',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        foreach ([
            'view-commerce-sales' => 'canViewCommerceSales',
            'record-commerce-sales' => 'canRecordCommerceSale',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        foreach ([
            'view-purchases' => 'canViewPurchases',
            'draft-purchase-orders' => 'canDraftPurchaseOrders',
            'issue-purchase-orders' => 'canIssuePurchaseOrders',
            'receive-purchases' => 'canReceivePurchases',
            'cancel-purchase-orders' => 'canCancelPurchaseOrders',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        Gate::define(
            'manage-organization',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canManageOrganization()
                ?? false
        );
        foreach ([
            'view-organization-members' => 'canViewOrganizationMembers',
            'manage-organization-members' => 'canManageOrganizationMembers',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        foreach ([
            'view-service-orders' => 'canViewServiceOrders',
            'create-service-orders' => 'canCreateServiceOrders',
            'record-service-diagnostics' => 'canRecordServiceDiagnostics',
            'issue-service-quotes' => 'canIssueServiceQuotes',
            'record-service-quote-decisions' => 'canRecordServiceQuoteDecisions',
            'plan-service-work' => 'canPlanServiceWork',
            'execute-service-work' => 'canExecuteServiceWork',
            'plan-service-parts' => 'canPlanServiceParts',
            'record-service-part-purchases' => 'canRecordServicePartPurchases',
            'consume-service-parts' => 'canConsumeServiceParts',
            'inspect-service-quality' => 'canInspectServiceQuality',
            'deliver-service-orders' => 'canDeliverServiceOrders',
            'request-service-cancellation' => 'canRequestServiceCancellation',
            'resolve-service-cancellation' => 'canResolveServiceCancellation',
            'transfer-service-custody' => 'canTransferServiceCustody',
            'return-cancelled-service-order' => 'canReturnCancelledServiceOrder',
            'register-service-warranty-claims' => 'canRegisterServiceWarrantyClaims',
            'resolve-service-warranty-claims' => 'canResolveServiceWarrantyClaims',
            'return-service-warranty-claims' => 'canReturnServiceWarrantyClaims',
            'view-service-evidence' => 'canViewServiceEvidence',
            'upload-service-evidence' => 'canUploadServiceEvidence',
            'verify-service-evidence' => 'canVerifyServiceEvidence',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        Gate::define(
            'view-inventory',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canViewInventory()
                ?? false
        );

        Gate::define(
            'manage-inventory-locations',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canManageInventoryLocations()
                ?? false
        );

        Gate::define(
            'view-inventory-availability',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canViewInventoryAvailability()
                ?? false
        );

        foreach ([
            'receive-inventory' => 'canReceiveInventory',
            'issue-inventory' => 'canIssueInventory',
            'transfer-inventory' => 'canTransferInventory',
            'draft-inventory-movements' => 'canDraftAnyInventoryMovement',
            'process-inventory-returns' => 'canProcessInventoryReturns',
            'adjust-inventory' => 'canAdjustInventory',
            'correct-inventory' => 'canCorrectInventory',
            'rebuild-inventory' => 'canRebuildInventory',
            'request-inventory-negative' => 'canRequestInventoryNegative',
            'override-inventory-negative' => 'canOverrideInventoryNegative',
            'view-inventory-negative-authorizations' => 'canViewInventoryNegativeAuthorizations',
            'view-inventory-negative-incidents' => 'canViewInventoryNegativeIncidents',
            'review-inventory-negative-incidents' => 'canReviewInventoryNegativeIncidents',
        ] as $ability => $method) {
            Gate::define(
                $ability,
                fn (User $user): bool => app(CurrentOrganization::class)
                    ->roleFor($user)
                    ?->{$method}()
                    ?? false
            );
        }

        Gate::define(
            'view-audit',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canViewAudit()
                ?? false
        );
    }
}
