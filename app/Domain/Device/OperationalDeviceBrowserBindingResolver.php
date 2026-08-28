<?php

namespace App\Domain\Device;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class OperationalDeviceBrowserBindingResolver
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function resolve(
        Request $request,
        User $actor
    ): ?OperationalDeviceBrowserBinding {
        return $this->resolveToken(
            $actor,
            $request->cookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME
            )
        );
    }

    public function resolveToken(
        User $actor,
        ?string $token
    ): ?OperationalDeviceBrowserBinding {
        $organization = $this->currentOrganization->getOrNull(
            $actor
        );

        if (! $organization) {
            return null;
        }

        $tokenHash =
            OperationalDeviceBrowserBindingManager::tokenHashOrNull(
                $token
            );

        if ($tokenHash === null) {
            return null;
        }

        $binding = OperationalDeviceBrowserBinding::query()
            ->forOrganization($organization)
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where(
                'expires_at',
                '>',
                CarbonImmutable::now()
            )
            ->with('device.capabilityGrants')
            ->first();

        if (
            ! $binding
            || ! $binding->device
            || ! $binding->device->active
        ) {
            return null;
        }

        return $binding;
    }
}
