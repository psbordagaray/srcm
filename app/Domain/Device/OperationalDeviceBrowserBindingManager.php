<?php

namespace App\Domain\Device;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\UserRole;
use App\Models\OperationalDevice;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationalDeviceBrowserBindingManager
{
    public const COOKIE_NAME = 'srcm_operational_device_binding_v1';
    public const TOKEN_BYTES = 32;
    public const TOKEN_HEX_LENGTH = self::TOKEN_BYTES * 2;
    public const TTL_DAYS = 90;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $auditRecorder,
    ) {
    }

    public function issue(
        User $actor,
        OperationalDevice $device
    ): OperationalDeviceBrowserBindingIssue {
        $organization = $this->currentOrganization->get($actor);

        $this->assertAdmin($actor);
        $this->assertOrganization($device, $organization);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $token);
        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addDays(self::TTL_DAYS);

        return DB::transaction(function () use (
            $actor,
            $organization,
            $device,
            $token,
            $tokenHash,
            $issuedAt,
            $expiresAt
        ): OperationalDeviceBrowserBindingIssue {
            $this->lockActiveOrganization(
                (int) $organization->getKey()
            );

            $lockedDevice = OperationalDevice::query()
                ->forOrganization($organization)
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedDevice) {
                throw new DomainException(
                    'El dispositivo operativo no pertenece a la organización activa.'
                );
            }

            if (! $lockedDevice->active) {
                throw new DomainException(
                    'El dispositivo operativo no está habilitado.'
                );
            }

            $priorBindings = OperationalDeviceBrowserBinding::query()
                ->forOrganization($organization)
                ->where(
                    'operational_device_id',
                    $lockedDevice->getKey()
                )
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $issuedAt)
                ->lockForUpdate()
                ->get();

            foreach ($priorBindings as $prior) {
                $prior->revoked_at = $issuedAt;
                $prior->save();

                $this->auditRecorder->record(
                    $prior,
                    'revoked',
                    ['revoked_at' => null],
                    [
                        'revoked_at' => $issuedAt,
                        'reason' => 'rotated',
                    ]
                );
            }

            $binding = OperationalDeviceBrowserBinding::query()
                ->create([
                    'organization_id' => $organization->getKey(),
                    'operational_device_id' =>
                        $lockedDevice->getKey(),
                    'public_id' => Str::uuid()->toString(),
                    'token_hash' => $tokenHash,
                    'issued_by_user_id' => $actor->getKey(),
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ]);

            $this->auditRecorder->record(
                $binding,
                'issued',
                null,
                [
                    'binding_public_id' => $binding->public_id,
                    'device_public_id' => $lockedDevice->public_id,
                    'expires_at' => $expiresAt,
                    'rotated_prior_bindings' =>
                        $priorBindings->count(),
                ]
            );

            return new OperationalDeviceBrowserBindingIssue(
                binding: $binding->load(
                    'device.capabilityGrants'
                ),
                token: $token,
            );
        }, 3);
    }

    public function revoke(
        User $actor,
        OperationalDeviceBrowserBinding $binding,
        string $reason = 'manual'
    ): OperationalDeviceBrowserBinding {
        $organization = $this->currentOrganization->get($actor);

        $this->assertAdmin($actor);
        $this->assertBindingOrganization(
            $binding,
            $organization
        );

        return DB::transaction(function () use (
            $organization,
            $binding,
            $reason
        ): OperationalDeviceBrowserBinding {
            $this->lockActiveOrganization(
                (int) $organization->getKey()
            );

            $locked = OperationalDeviceBrowserBinding::query()
                ->forOrganization($organization)
                ->whereKey($binding->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $revokedAt = CarbonImmutable::now();
            $locked->revoked_at = $revokedAt;
            $locked->save();

            $this->auditRecorder->record(
                $locked,
                'revoked',
                ['revoked_at' => null],
                [
                    'revoked_at' => $revokedAt,
                    'reason' => Str::of($reason)
                        ->squish()
                        ->limit(80, '')
                        ->toString(),
                ]
            );

            return $locked->fresh();
        }, 3);
    }

    public function revokeByToken(
        User $actor,
        ?string $token
    ): ?OperationalDeviceBrowserBinding {
        $organization = $this->currentOrganization->get($actor);

        $this->assertAdmin($actor);

        $tokenHash = $this->tokenHashOrNull($token);

        if ($tokenHash === null) {
            return null;
        }

        $binding = OperationalDeviceBrowserBinding::query()
            ->forOrganization($organization)
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $binding) {
            return null;
        }

        return $this->revoke(
            $actor,
            $binding,
            'current_browser_unbound'
        );
    }

    public static function tokenHashOrNull(
        ?string $token
    ): ?string {
        if (
            ! is_string($token)
            || preg_match(
                '/^[0-9a-f]{'.self::TOKEN_HEX_LENGTH.'}$/D',
                $token
            ) !== 1
        ) {
            return null;
        }

        return hash('sha256', $token);
    }

    private function assertAdmin(User $actor): void
    {
        if (
            $this->currentOrganization->roleFor($actor)
                !== UserRole::Admin
        ) {
            throw new DomainException(
                'Sólo un administrador puede vincular navegadores a dispositivos operativos.'
            );
        }
    }

    private function assertOrganization(
        OperationalDevice $device,
        Organization $organization
    ): void {
        if (
            (int) $device->organization_id
                !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'El dispositivo operativo no pertenece a la organización activa.'
            );
        }
    }

    private function assertBindingOrganization(
        OperationalDeviceBrowserBinding $binding,
        Organization $organization
    ): void {
        if (
            (int) $binding->organization_id
                !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'El binding del navegador no pertenece a la organización activa.'
            );
        }
    }

    private function lockActiveOrganization(int $organizationId): void
    {
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }
}
