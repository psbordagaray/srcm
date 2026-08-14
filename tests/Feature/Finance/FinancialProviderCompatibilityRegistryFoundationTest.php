<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Models\FinancialProviderCompatibility;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialProviderCompatibilityRegistryFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_registry_is_global_structured_and_secret_free(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_compatibilities',
                [
                    'registry_key',
                    'provider_key',
                    'provider_label',
                    'provider_contract_version',
                    'provider_contract_reference',
                    'adapter_class',
                    'adapter_contract_version',
                    'compatibility_status',
                    'migration_required',
                    'srcm_version',
                    'verified_at',
                    'notes',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_capability_compatibilities',
                [
                    'financial_provider_compatibility_id',
                    'capability',
                    'compatibility_status',
                    'required',
                    'evidence_reference',
                    'notes',
                ]
            )
        );

        foreach ([
            'organization_id',
            'financial_provider_connection_id',
            'access_token',
            'refresh_token',
            'client_secret',
            'api_key',
            'webhook_secret',
            'password',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn(
                    'financial_provider_compatibilities',
                    $forbidden
                )
            );
        }
    }

    public function test_reference_registry_is_provider_neutral_and_idempotent(): void
    {
        $registry = app(
            FinancialProviderCompatibilityRegistry::class
        );

        [$mercadoPago, $payway] =
            $registry->seedReferenceRegistry();

        [$mercadoPagoRetry, $paywayRetry] =
            $registry->seedReferenceRegistry();

        $this->assertSame(
            $mercadoPago->id,
            $mercadoPagoRetry->id
        );
        $this->assertSame(
            $payway->id,
            $paywayRetry->id
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            2
        );
        $this->assertDatabaseCount(
            'financial_provider_capability_compatibilities',
            10
        );

        $this->assertSame(
            FinancialProviderCompatibilityStatus::Compatible,
            $mercadoPago->compatibility_status
        );
        $this->assertFalse(
            $mercadoPago->migration_required
        );
        $this->assertSame(
            'mercado-pago',
            $mercadoPago->provider_key
        );
        $this->assertSame(
            'orders-v1',
            $mercadoPago->provider_contract_version
        );
        $this->assertNotNull(
            $mercadoPago->adapter_class
        );

        $requiredStatuses = $mercadoPago->capabilities
            ->where('required', true)
            ->pluck('compatibility_status')
            ->all();

        foreach ($requiredStatuses as $status) {
            $this->assertSame(
                FinancialProviderCompatibilityStatus::Compatible,
                $status
            );
        }

        $refund = $mercadoPago->capabilities
            ->firstWhere(
                'capability',
                FinancialProviderCapability::Refund
            );

        $this->assertNotNull($refund);
        $this->assertFalse($refund->required);
        $this->assertSame(
            FinancialProviderCompatibilityStatus::Unknown,
            $refund->compatibility_status
        );

        $this->assertSame(
            'payway',
            $payway->provider_key
        );
        $this->assertSame(
            FinancialProviderCompatibilityStatus::Unknown,
            $payway->compatibility_status
        );
        $this->assertNull($payway->adapter_class);
    }

    public function test_same_registry_key_with_different_evidence_fails_closed(): void
    {
        $registry = app(
            FinancialProviderCompatibilityRegistry::class
        );

        $registry->seedReferenceRegistry();

        $this->assertDomainFailure(fn () =>
            $registry->register(
                registryKey:
                    'mercado-pago:orders-v1:point-v1:dc41bda',
                providerKey: 'mercado-pago',
                providerLabel: 'Mercado Pago',
                providerContractVersion: 'orders-v1',
                providerContractReference:
                    'Evidencia conflictiva',
                adapterClass:
                    'App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter',
                adapterContractVersion: 'point-v1',
                status:
                    FinancialProviderCompatibilityStatus::Compatible,
                migrationRequired: false,
                srcmVersion:
                    'dc41bda2323062b7ab4f6e165f42d2388a921306',
                verifiedAt: CarbonImmutable::parse(
                    '2026-08-14 00:00:00',
                    'America/Argentina/Buenos_Aires'
                ),
                capabilities: [
                    [
                        'capability' =>
                            FinancialProviderCapability::Webhook,
                        'status' =>
                            FinancialProviderCompatibilityStatus::Compatible,
                        'required' => true,
                        'evidence_reference' =>
                            'Conflictiva',
                        'notes' => null,
                    ],
                ]
            )
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            2
        );
    }

    public function test_compatible_status_requires_all_required_capabilities_green(): void
    {
        $registry = app(
            FinancialProviderCompatibilityRegistry::class
        );

        $this->assertDomainFailure(fn () =>
            $registry->register(
                registryKey: 'demo:v1:a1:dc41bda',
                providerKey: 'demo',
                providerLabel: 'Demo',
                providerContractVersion: 'v1',
                providerContractReference: 'Test',
                adapterClass: 'App\Adapters\Demo',
                adapterContractVersion: 'a1',
                status:
                    FinancialProviderCompatibilityStatus::Compatible,
                migrationRequired: false,
                srcmVersion: 'dc41bda',
                verifiedAt: now(),
                capabilities: [
                    [
                        'capability' =>
                            FinancialProviderCapability::Webhook,
                        'status' =>
                            FinancialProviderCompatibilityStatus::Unknown,
                        'required' => true,
                        'evidence_reference' =>
                            'No verificada',
                        'notes' => null,
                    ],
                ]
            )
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            0
        );
    }

    public function test_registry_snapshots_are_immutable_at_model_and_database_boundaries(): void
    {
        [$compatibility] = app(
            FinancialProviderCompatibilityRegistry::class
        )->seedReferenceRegistry();

        $this->assertDomainFailure(function () use (
            $compatibility
        ): void {
            $compatibility->forceFill([
                'notes' => 'mutación prohibida',
            ])->save();
        });

        $this->assertQueryFailure(fn () =>
            DB::table('financial_provider_compatibilities')
                ->where('id', $compatibility->id)
                ->update([
                    'notes' => 'mutación SQL prohibida',
                ])
        );

        $this->assertQueryFailure(fn () =>
            DB::table('financial_provider_compatibilities')
                ->where('id', $compatibility->id)
                ->delete()
        );

        $capability = $compatibility->capabilities()->firstOrFail();

        $this->assertQueryFailure(fn () =>
            DB::table(
                'financial_provider_capability_compatibilities'
            )
                ->where('id', $capability->id)
                ->update([
                    'notes' => 'mutación SQL prohibida',
                ])
        );
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba QueryException.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
