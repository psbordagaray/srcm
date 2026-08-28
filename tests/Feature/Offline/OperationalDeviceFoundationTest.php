<?php

namespace Tests\Feature\Offline;

use App\Domain\Device\OperationalDeviceOperationData;
use App\Domain\Device\OperationalDeviceRegistry;
use App\Domain\Device\OperationalDeviceReplayGuard;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Models\OperationalDevice;
use App\Models\OperationalDeviceOperationClaim;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OperationalDeviceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_uses_active_tenant_opaque_identity_capability_and_audit(): void
    {
        $organization = $this->organization('Operación A');
        $admin = $this->actor($organization, UserRole::Admin);

        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                '  POS mostrador 01  ',
                [
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                ]
            );

        $this->assertSame(
            $organization->id,
            $device->organization_id
        );
        $this->assertSame('POS mostrador 01', $device->label);
        $this->assertTrue(Str::isUuid($device->public_id));
        $this->assertTrue($device->active);
        $this->assertCount(1, $device->capabilityGrants);
        $this->assertSame(
            OperationalDeviceCapability::RestrictedOfflineReplay,
            $device->capabilityGrants->sole()->capability
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' => OperationalDevice::class,
            'auditable_id' => (string) $device->id,
            'event' => 'registered',
            'user_id' => $admin->id,
        ]);

        $other = $this->organization('Operación B');
        $otherAdmin = $this->actor($other, UserRole::Admin);

        $this->actingAs($otherAdmin);

        $sameLabelOtherTenant = app(
            OperationalDeviceRegistry::class
        )->register(
            $otherAdmin,
            'POS mostrador 01',
            [OperationalDeviceCapability::RestrictedOfflineReplay]
        );

        $this->assertNotSame(
            $device->public_id,
            $sameLabelOtherTenant->public_id
        );
        $this->assertSame(
            $other->id,
            $sameLabelOtherTenant->organization_id
        );
    }

    public function test_only_admin_can_register_operational_devices(): void
    {
        $organization = $this->organization('Operación segura');
        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        $this->actingAs($operator);

        $this->assertDomainRejected(
            fn () => app(OperationalDeviceRegistry::class)
                ->register(
                    $operator,
                    'POS operador',
                    [
                        OperationalDeviceCapability::RestrictedOfflineReplay,
                    ]
                ),
            'Sólo un administrador'
        );

        $this->assertDatabaseCount('operational_devices', 0);
    }

    public function test_exact_operation_replays_but_changed_content_conflicts(): void
    {
        $organization = $this->organization('Replay');
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'Terminal offline',
                [
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                ]
            );

        $operationId = Str::uuid()->toString();
        $guard = app(OperationalDeviceReplayGuard::class);

        $first = $guard->claim(
            $organization,
            $device,
            new OperationalDeviceOperationData(
                clientOperationId: $operationId,
                capability:
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                operationType: 'commerce.sale.prepare',
                payload: [
                    'sale_reference' => 'LOCAL-42',
                    'total_minor' => 125000,
                    'currency' => 'ARS',
                ],
            )
        );

        $replay = $guard->claim(
            $organization,
            $device,
            new OperationalDeviceOperationData(
                clientOperationId: $operationId,
                capability:
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                operationType: 'commerce.sale.prepare',
                payload: [
                    'currency' => 'ARS',
                    'total_minor' => 125000,
                    'sale_reference' => 'LOCAL-42',
                ],
            )
        );

        $this->assertFalse($first->replay);
        $this->assertTrue($replay->replay);
        $this->assertSame(
            $first->claim->id,
            $replay->claim->id
        );
        $this->assertDatabaseCount(
            'operational_device_operation_claims',
            1
        );

        $this->assertDomainRejected(
            fn () => $guard->claim(
                $organization,
                $device,
                new OperationalDeviceOperationData(
                    clientOperationId: $operationId,
                    capability:
                        OperationalDeviceCapability::RestrictedOfflineReplay,
                    operationType: 'commerce.sale.prepare',
                    payload: [
                        'sale_reference' => 'LOCAL-42',
                        'total_minor' => 126000,
                        'currency' => 'ARS',
                    ],
                )
            ),
            'ya fue utilizado con otro contenido'
        );

        $this->assertDatabaseCount(
            'operational_device_operation_claims',
            1
        );
    }

    public function test_missing_capability_inactive_device_and_foreign_tenant_fail_closed(): void
    {
        $organization = $this->organization('Fail closed');
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $registry = app(OperationalDeviceRegistry::class);
        $guard = app(OperationalDeviceReplayGuard::class);

        $withoutCapability = $registry->register(
            $admin,
            'Terminal sin capacidad',
            []
        );

        $data = new OperationalDeviceOperationData(
            clientOperationId: Str::uuid()->toString(),
            capability:
                OperationalDeviceCapability::RestrictedOfflineReplay,
            operationType: 'inventory.issue.prepare',
            payload: ['reference' => 'OFF-1'],
        );

        $this->assertDomainRejected(
            fn () => $guard->claim(
                $organization,
                $withoutCapability,
                $data
            ),
            'no posee la capacidad requerida'
        );

        $enabled = $registry->register(
            $admin,
            'Terminal habilitada',
            [OperationalDeviceCapability::RestrictedOfflineReplay]
        );

        $registry->deactivate($admin, $enabled);

        $this->assertDomainRejected(
            fn () => $guard->claim(
                $organization,
                $enabled->fresh(),
                new OperationalDeviceOperationData(
                    clientOperationId: Str::uuid()->toString(),
                    capability:
                        OperationalDeviceCapability::RestrictedOfflineReplay,
                    operationType: 'inventory.issue.prepare',
                    payload: ['reference' => 'OFF-2'],
                )
            ),
            'no está habilitado'
        );

        $other = $this->organization('Tenant ajeno');

        $this->assertDomainRejected(
            fn () => $guard->claim(
                $other,
                $withoutCapability,
                new OperationalDeviceOperationData(
                    clientOperationId: Str::uuid()->toString(),
                    capability:
                        OperationalDeviceCapability::RestrictedOfflineReplay,
                    operationType: 'inventory.issue.prepare',
                )
            ),
            'no pertenece a la organización solicitada'
        );
    }

    public function test_claims_are_append_only_and_device_identity_does_not_reuse_service_identifiers(): void
    {
        $organization = $this->organization('Append only');
        $admin = $this->actor($organization, UserRole::Admin);
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'Terminal inmutable',
                [OperationalDeviceCapability::RestrictedOfflineReplay]
            );

        $claim = app(OperationalDeviceReplayGuard::class)
            ->claim(
                $organization,
                $device,
                new OperationalDeviceOperationData(
                    clientOperationId: Str::uuid()->toString(),
                    capability:
                        OperationalDeviceCapability::RestrictedOfflineReplay,
                    operationType: 'commerce.sale.prepare',
                    payload: ['reference' => 'APPEND-1'],
                )
            )
            ->claim;

        $claim->operation_type = 'commerce.sale.changed';

        try {
            $claim->save();
            $this->fail(
                'Un claim de idempotencia no debe poder modificarse.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'inmutables',
                $exception->getMessage()
            );
        }

        try {
            $claim->fresh()->delete();
            $this->fail(
                'Un claim de idempotencia no debe poder eliminarse.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'no pueden eliminarse físicamente',
                $exception->getMessage()
            );
        }

        foreach ([
            'imei',
            'serial_number',
            'asset_tag',
            'vendor_id',
        ] as $forbiddenColumn) {
            $this->assertFalse(
                Schema::hasColumn(
                    'operational_devices',
                    $forbiddenColumn
                ),
                'La identidad operacional no debe reutilizar '.$forbiddenColumn
            );
        }

        $this->assertDatabaseHas(
            'operational_device_operation_claims',
            ['id' => $claim->id]
        );
    }

    private function organization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'active' => true,
            ])
        );
    }

    private function actor(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user;
    }

    private function assertDomainRejected(
        callable $operation,
        string $expectedMessage
    ): void {
        try {
            $operation();
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                $expectedMessage,
                $exception->getMessage()
            );

            return;
        }

        $this->fail('La operación de dominio debía ser rechazada.');
    }
}
