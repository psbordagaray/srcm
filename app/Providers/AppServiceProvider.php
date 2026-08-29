<?php

namespace App\Providers;

use App\Adapters\Offline\ConfiguredRestrictedOfflineTrustedPublicKeyringProvider;
use App\Adapters\Offline\EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider;
use App\Adapters\Offline\WebAuthnRestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Contracts\Offline\RestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Contracts\Offline\RestrictedOfflineSignedGrantSigningKeyProvider;
use App\Contracts\Offline\RestrictedOfflineTrustedPublicKeyringProvider;
use App\Adapters\Finance\MercadoPago\EnvironmentMercadoPagoConnectionSecretStore;
use App\Adapters\Fiscal\Arca\DomWsaaLoginCmsResponseParser;
use App\Adapters\Fiscal\Arca\DomWsfeCompUltimoAutorizadoSoapResponseParser;
use App\Adapters\Fiscal\Arca\DomWsfeCompUltimoAutorizadoSoapSerializer;
use App\Adapters\Fiscal\Arca\DomWsfeFecaeSoapResponseParser;
use App\Adapters\Fiscal\Arca\DomWsfeFecaeSoapSerializer;
use App\Adapters\Fiscal\Arca\EncryptedCacheWsaaAccessTicketProvider;
use App\Adapters\Fiscal\Arca\EnvironmentFiscalAuthorizationRuntimeScopeStore;
use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialProvider;
use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialReferenceStore;
use App\Adapters\Fiscal\Arca\GuzzleWsaaLoginCmsTransport;
use App\Adapters\Fiscal\Arca\GuzzleWsfeCompUltimoAutorizadoSoapTransport;
use App\Adapters\Fiscal\Arca\GuzzleWsfeFecaeSoapTransport;
use App\Adapters\Fiscal\Arca\NativeWsaaCmsProcessRunner;
use App\Adapters\Fiscal\Arca\OfficialWsaaCmsDigestPolicy;
use App\Adapters\Fiscal\Arca\OpenSslCliWsaaCmsSigner;
use App\Adapters\Fiscal\Arca\OpenSslWsaaCredentialMaterialValidator;
use App\Adapters\Fiscal\Arca\RandomWsaaTraUniqueIdProvider;
use App\Adapters\Fiscal\Arca\SystemWsaaTraClock;
use App\Adapters\Fiscal\Arca\WsaaBackedFiscalAuthorizationTransport;
use App\Adapters\Fiscal\Arca\WsaaBackedFiscalRemoteSequenceAuthority;
use App\Adapters\Fiscal\Arca\WsaaCmsProcessRunner;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointRefundAdapter;
use App\Adapters\Resilience\EnvironmentBackupEncryptionKeyResolver;
use App\Adapters\Resilience\LaravelFilesystemOffHostBackupTransport;
use App\Contracts\Resilience\BackupEncryptionKeyResolver;
use App\Contracts\Resilience\OffHostBackupTransport;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialProviderRefundAdapterRegistry;
use App\Domain\Fiscal\ArcaHomologationReadiness;
use App\Domain\Fiscal\FiscalAuthorizationCredentialStore;
use App\Domain\Fiscal\FiscalAuthorizationRuntimeScopeStore;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore;
use App\Domain\Fiscal\WsaaCredentialMaterialValidator;
use App\Domain\Fiscal\WsaaLoginCmsResponseParser;
use App\Domain\Fiscal\WsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaLoginTicketRequestBuilder;
use App\Domain\Fiscal\WsaaTraBuilder;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsaaTraUniqueIdProvider;
use App\Domain\Fiscal\WsaaTraWindowPolicy;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapResponseParser;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapSerializer;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapTransport;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizer;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizerContract;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergence;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergenceContract;
use App\Domain\Fiscal\WsfeFecaeRequestComposer;
use App\Domain\Fiscal\WsfeFecaeRequestComposerContract;
use App\Domain\Fiscal\WsfeFecaeSoapResponseParser;
use App\Domain\Fiscal\WsfeFecaeSoapSerializer;
use App\Domain\Fiscal\WsfeFecaeSoapTransport;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passkeys::ignoreRoutes();

        $this->app->scoped(
            CurrentOrganization::class,
            fn () => new CurrentOrganization
        );
        $this->app->singleton(
            RestrictedOfflineSignedGrantSigningKeyProvider::class,
            EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider::class
        );
        $this->app->singleton(
            RestrictedOfflineTrustedPublicKeyringProvider::class,
            ConfiguredRestrictedOfflineTrustedPublicKeyringProvider::class
        );
        $this->app->singleton(
            RestrictedOfflineSignedGrantCredentialMaterialExtractor::class,
            WebAuthnRestrictedOfflineSignedGrantCredentialMaterialExtractor::class
        );
        $this->app->singleton(
            WsaaCredentialMaterialReferenceStore::class,
            EnvironmentWsaaCredentialMaterialReferenceStore::class
        );
        $this->app->singleton(
            WsaaCredentialMaterialValidator::class,
            OpenSslWsaaCredentialMaterialValidator::class
        );
        $this->app->singleton(
            WsaaCredentialMaterialProvider::class,
            EnvironmentWsaaCredentialMaterialProvider::class
        );
        $this->app->singleton(
            WsaaCmsProcessRunner::class,
            NativeWsaaCmsProcessRunner::class
        );
        $this->app->singleton(
            WsaaCmsSigner::class,
            OpenSslCliWsaaCmsSigner::class
        );
        $this->app->singleton(
            WsaaLoginCmsResponseParser::class,
            DomWsaaLoginCmsResponseParser::class
        );
        $this->app->singleton(
            WsaaLoginCmsTransport::class,
            fn ($app) =>
                new GuzzleWsaaLoginCmsTransport(
                    $app->make(
                        WsaaLoginCmsResponseParser::class
                    )
                )
        );
        $this->app->singleton(
            WsaaTraClock::class,
            SystemWsaaTraClock::class
        );
        $this->app->singleton(
            WsaaTraUniqueIdProvider::class,
            RandomWsaaTraUniqueIdProvider::class
        );
        $this->app->singleton(
            WsaaTraWindowPolicy::class,
            fn () => new WsaaTraWindowPolicy(
                generationBackSeconds: 60,
                expirationForwardSeconds: 600,
            )
        );
        $this->app->singleton(
            WsaaTraBuilder::class,
            fn ($app) => new WsaaLoginTicketRequestBuilder(
                $app->make(WsaaTraClock::class),
                $app->make(WsaaTraUniqueIdProvider::class),
                $app->make(WsaaTraWindowPolicy::class),
            )
        );
        $this->app->singleton(
            WsaaCmsDigestPolicy::class,
            OfficialWsaaCmsDigestPolicy::class
        );
        $this->app->singleton(
            WsaaAccessTicketProvider::class,
            fn ($app) => new EncryptedCacheWsaaAccessTicketProvider(
                $app['cache']->store('database'),
                $app['encrypter'],
                $app->make(WsaaTraClock::class),
                $app->make(WsaaTraBuilder::class),
                $app->make(WsaaCredentialMaterialProvider::class),
                $app->make(WsaaCmsDigestPolicy::class),
                $app->make(WsaaCmsSigner::class),
                $app->make(WsaaLoginCmsTransport::class),
            )
        );
        $this->app->singleton(
            WsfeCompUltimoAutorizadoSoapSerializer::class,
            DomWsfeCompUltimoAutorizadoSoapSerializer::class
        );
        $this->app->singleton(
            WsfeCompUltimoAutorizadoSoapResponseParser::class,
            DomWsfeCompUltimoAutorizadoSoapResponseParser::class
        );
        $this->app->singleton(
            WsfeCompUltimoAutorizadoSoapTransport::class,
            fn ($app) => new GuzzleWsfeCompUltimoAutorizadoSoapTransport(
                $app->make(WsfeCompUltimoAutorizadoSoapSerializer::class),
                $app->make(WsfeCompUltimoAutorizadoSoapResponseParser::class),
            )
        );
        $this->app->singleton(
            WsfeFecaeSoapSerializer::class,
            DomWsfeFecaeSoapSerializer::class
        );
        $this->app->singleton(
            WsfeFecaeSoapResponseParser::class,
            DomWsfeFecaeSoapResponseParser::class
        );
        $this->app->singleton(
            WsfeFecaeSoapTransport::class,
            fn ($app) => new GuzzleWsfeFecaeSoapTransport(
                $app->make(WsfeFecaeSoapSerializer::class),
                $app->make(WsfeFecaeSoapResponseParser::class),
            )
        );
        $this->app->singleton(
            EnvironmentFiscalAuthorizationRuntimeScopeStore::class,
            fn () => new EnvironmentFiscalAuthorizationRuntimeScopeStore
        );
        $this->app->singleton(
            FiscalAuthorizationRuntimeScopeStore::class,
            fn ($app) => $app->make(EnvironmentFiscalAuthorizationRuntimeScopeStore::class)
        );
        $this->app->singleton(
            FiscalAuthorizationCredentialStore::class,
            fn ($app) => $app->make(EnvironmentFiscalAuthorizationRuntimeScopeStore::class)
        );
        $this->app->singleton(
            WsfeFecaeRequestComposerContract::class,
            WsfeFecaeRequestComposer::class
        );
        $this->app->singleton(
            WsfeFecaeProviderResponseNormalizerContract::class,
            WsfeFecaeProviderResponseNormalizer::class
        );
        $this->app->singleton(
            WsfeFecaeProviderResultConvergenceContract::class,
            WsfeFecaeProviderResultConvergence::class
        );
        $this->app->singleton(
            FiscalRemoteSequenceAuthority::class,
            fn ($app) => new WsaaBackedFiscalRemoteSequenceAuthority(
                $app->make(ArcaHomologationReadiness::class),
                $app->make(FiscalAuthorizationRuntimeScopeStore::class),
                $app->make(WsaaAccessTicketProvider::class),
                $app->make(WsfeCompUltimoAutorizadoSoapTransport::class),
                $app->make(WsaaTraClock::class),
            )
        );
        $this->app->singleton(
            FiscalAuthorizationTransport::class,
            fn ($app) => new WsaaBackedFiscalAuthorizationTransport(
                $app->make(ArcaHomologationReadiness::class),
                $app->make(FiscalAuthorizationRuntimeScopeStore::class),
                $app->make(WsaaAccessTicketProvider::class),
                $app->make(WsfeFecaeSoapTransport::class),
                $app->make(WsfeFecaeProviderResponseNormalizerContract::class),
                $app->make(WsfeFecaeProviderResultConvergenceContract::class),
                $app->make(WsaaTraClock::class),
            )
        );
        $this->app->singleton(
            MercadoPagoConnectionSecretStore::class,
            EnvironmentMercadoPagoConnectionSecretStore::class
        );
        $this->app->singleton(
            BackupEncryptionKeyResolver::class,
            EnvironmentBackupEncryptionKeyResolver::class
        );
        $this->app->singleton(
            OffHostBackupTransport::class,
            LaravelFilesystemOffHostBackupTransport::class
        );

        $this->app->singleton(
            FinancialProviderRefundAdapterRegistry::class,
            fn ($app) =>
                new FinancialProviderRefundAdapterRegistry([
                    $app->make(
                        MercadoPagoPointRefundAdapter::class
                    ),
                ])
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

        RateLimiter::for(
            'restricted-offline-signed-grant',
            function (Request $request): Limit {
                $user = $request->user();
                $organizationId = $user
                    ? app(CurrentOrganization::class)->idOrNull($user)
                    : null;

                return Limit::perMinute(6)->by(implode('|', [
                    (string) ($user?->getAuthIdentifier() ?? 'guest'),
                    (string) ($organizationId ?? 0),
                    (string) ($request->ip() ?? 'unknown'),
                ]));
            }
        );

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
            'create-customer-receivables' =>
                'canCreateCustomerReceivable',
            'manage-customer-credit-policies' =>
                'canManageCustomerCreditPolicy',
            'override-customer-credit' =>
                'canOverrideCustomerCredit',
            'view-customer-receivables' =>
                'canViewCustomerReceivables',
            'view-customer-account' =>
                'canViewCustomerAccount',
            'record-customer-collections' =>
                'canRecordCustomerCollections',
            'view-commerce-post-sale' =>
                'canViewCommercePostSaleRequests',
            'record-commerce-post-sale' =>
                'canRecordCommercePostSaleRequest',
            'resolve-commerce-post-sale' =>
                'canResolveCommercePostSale',
            'materialize-commerce-post-sale-customer-credit' =>
                'canMaterializeCommercePostSaleCustomerCredit',
            'execute-commerce-post-sale-cash-refund' =>
                'canExecuteCommercePostSaleCashRefund',
            'execute-commerce-post-sale-external-refund' =>
                'canExecuteCommercePostSaleExternalRefund',
            'select-commerce-post-sale-exchange' =>
                'canResolveCommercePostSale',
            'execute-commerce-post-sale-exchange' =>
                'canExecuteCommercePostSaleExchange',
            'dispatch-commerce-post-sale-external-refund' =>
                'canExecuteCommercePostSaleExternalRefund',
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
            'manage-commerce-prices',
            fn (User $user): bool => app(CurrentOrganization::class)
                ->roleFor($user)
                ?->canManageCommercePrices()
                ?? false
        );

        foreach ([
            'view-purchases' => 'canViewPurchases',
            'draft-purchase-orders' => 'canDraftPurchaseOrders',
            'issue-purchase-orders' => 'canIssuePurchaseOrders',
            'receive-purchases' => 'canReceivePurchases',
            'create-purchase-obligations' =>
                'canCreatePurchaseObligations',
            'request-purchase-payments' =>
                'canRequestPurchasePayments',
            'approve-purchase-payments' =>
                'canApprovePurchasePayments',
            'execute-purchase-payments' =>
                'canExecutePurchasePayments',
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

        foreach ([
            'manage-cash-registers' => 'canManageCashRegisters',
            'operate-cash-register' => 'canOperateCashRegister',
            'supervise-cash-registers' => 'canSuperviseCashRegisters',
            'request-cash-security-drop' => 'canRequestCashSecurityDrop',
            'approve-cash-security-drop' => 'canApproveCashSecurityDrop',
            'execute-cash-security-drop' => 'canExecuteCashSecurityDrop',
            'use-financial-accounts' => 'canUseFinancialAccounts',
            'manage-financial-accounts' => 'canManageFinancialAccounts',
            'review-financial-reconciliation' =>
                'canReviewFinancialReconciliation',
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
