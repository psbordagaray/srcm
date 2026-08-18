<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalOrganizationProfile;
use App\Models\FiscalPointOfSale;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalPointOfSaleManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function create(
        FiscalPointOfSaleData $data,
        User $actor
    ): FiscalPointOfSale {
        $organizationId = $this->organizationId($actor);

        if ($data->pointNumber < 1 || $data->pointNumber > 99999) {
            throw new DomainException(
                'El punto de venta fiscal debe estar entre 1 y 99999.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $data,
            $actor
        ): FiscalPointOfSale {
            $profile = FiscalOrganizationProfile::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                throw new DomainException(
                    'Debe completar el perfil fiscal antes de crear puntos de venta.'
                );
            }

            $existing = FiscalPointOfSale::query()
                ->forOrganization($organizationId)
                ->where('environment', $data->environment->value)
                ->where('point_number', $data->pointNumber)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->integration_mode !== $data->integrationMode
                    || (int) $existing->fiscal_organization_profile_id
                        !== (int) $profile->getKey()
                ) {
                    throw new DomainException(
                        'El punto de venta ya existe con otra identidad fiscal.'
                    );
                }

                return $existing;
            }

            $point = FiscalPointOfSale::query()->create([
                'organization_id' => $organizationId,
                'fiscal_organization_profile_id' => $profile->getKey(),
                'environment' => $data->environment,
                'point_number' => $data->pointNumber,
                'integration_mode' => $data->integrationMode,
                'active' => true,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            $this->audit->record(
                $point,
                'fiscal_point_of_sale_created',
                null,
                [
                    'environment' => $data->environment->value,
                    'point_number' => $data->pointNumber,
                    'integration_mode' => $data->integrationMode->value,
                    'active' => true,
                ]
            );

            return $point->refresh()->load('profile');
        }, 3);
    }

    public function toggleActive(
        FiscalPointOfSale $point,
        User $actor
    ): FiscalPointOfSale {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $organizationId,
            $point,
            $actor
        ): FiscalPointOfSale {
            $locked = FiscalPointOfSale::query()
                ->forOrganization($organizationId)
                ->whereKey($point->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'El punto de venta fiscal no pertenece a la organización activa.'
                );
            }

            $old = ['active' => $locked->active];
            $locked->forceFill([
                'active' => ! $locked->active,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                $locked,
                'fiscal_point_of_sale_toggled',
                $old,
                ['active' => $locked->active]
            );

            return $locked->refresh();
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede modificar la configuración fiscal.'
            );
        }

        return $organizationId;
    }
}

