<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalOrganizationProfile;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FiscalOrganizationProfileManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function save(
        FiscalOrganizationProfileData $data,
        User $actor
    ): FiscalOrganizationProfile {
        $organizationId = $this->organizationId($actor);
        $values = $this->normalize($data);

        return DB::transaction(function () use (
            $organizationId,
            $values,
            $actor
        ): FiscalOrganizationProfile {
            Organization::query()
                ->whereKey($organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $profile = FiscalOrganizationProfile::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->first();

            $old = $profile?->only([
                'legal_name',
                'tax_id',
                'vat_condition_code',
                'gross_income_number',
                'activity_started_on',
                'address_line',
                'city',
                'province_code',
                'postal_code',
                'country_code',
            ]);

            if ($profile) {
                if (
                    $profile->pointsOfSale()->exists()
                    && $profile->tax_id !== $values['tax_id']
                ) {
                    throw new DomainException(
                        'El CUIT no puede cambiar mientras existan puntos de venta fiscales.'
                    );
                }

                $profile->fill($values);
                $profile->updated_by_user_id = $actor->getKey();

                if (! $profile->isDirty()) {
                    return $profile->refresh()->load('pointsOfSale');
                }

                $profile->save();
            } else {
                $profile = FiscalOrganizationProfile::query()->create([
                    'organization_id' => $organizationId,
                    ...$values,
                    'created_by_user_id' => $actor->getKey(),
                    'updated_by_user_id' => $actor->getKey(),
                ]);
            }

            $this->audit->record(
                $profile,
                $old === null
                    ? 'fiscal_organization_profile_created'
                    : 'fiscal_organization_profile_updated',
                $old,
                $values
            );

            return $profile->refresh()->load('pointsOfSale');
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

    /** @return array<string, string|null> */
    private function normalize(
        FiscalOrganizationProfileData $data
    ): array {
        $legalName = Str::of($data->legalName)->squish()->toString();
        $taxId = preg_replace('/\D+/', '', $data->taxId) ?? '';
        $vatCondition = trim($data->vatConditionCode);
        $grossIncome = filled($data->grossIncomeNumber)
            ? Str::of((string) $data->grossIncomeNumber)
                ->squish()
                ->toString()
            : null;
        $activityStartedOn = trim($data->activityStartedOn);
        $addressLine = Str::of($data->addressLine)->squish()->toString();
        $city = Str::of($data->city)->squish()->toString();
        $provinceCode = Str::upper(trim($data->provinceCode));
        $postalCode = Str::upper(trim($data->postalCode));
        $countryCode = Str::upper(trim($data->countryCode));

        if ($legalName === '' || mb_strlen($legalName) > 191) {
            throw new DomainException('La razón social fiscal no es válida.');
        }

        if (! $this->isValidCuit($taxId)) {
            throw new DomainException('El CUIT fiscal no es válido.');
        }

        if (
            preg_match('/^\d{1,10}$/D', $vatCondition) !== 1
        ) {
            throw new DomainException(
                'La condición IVA debe usar el código vigente informado por ARCA.'
            );
        }

        if ($grossIncome !== null && mb_strlen($grossIncome) > 50) {
            throw new DomainException(
                'El número de Ingresos Brutos no es válido.'
            );
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $activityStartedOn
        );

        if (! $date || $date->format('Y-m-d') !== $activityStartedOn) {
            throw new DomainException(
                'La fecha de inicio de actividades no es válida.'
            );
        }

        foreach ([
            'domicilio fiscal' => $addressLine,
            'localidad' => $city,
            'código postal' => $postalCode,
        ] as $label => $value) {
            if ($value === '' || mb_strlen($value) > 191) {
                throw new DomainException("El {$label} no es válido.");
            }
        }

        if (
            preg_match('/^[A-Z0-9-]{1,10}$/D', $provinceCode) !== 1
        ) {
            throw new DomainException('El código de provincia no es válido.');
        }

        if ($countryCode !== 'AR') {
            throw new DomainException(
                'P10.1 sólo habilita configuración fiscal argentina.'
            );
        }

        return [
            'legal_name' => $legalName,
            'tax_id' => $taxId,
            'vat_condition_code' => $vatCondition,
            'gross_income_number' => $grossIncome,
            'activity_started_on' => $activityStartedOn,
            'address_line' => $addressLine,
            'city' => $city,
            'province_code' => $provinceCode,
            'postal_code' => $postalCode,
            'country_code' => $countryCode,
        ];
    }

    private function isValidCuit(string $taxId): bool
    {
        if (preg_match('/^\d{11}$/D', $taxId) !== 1) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += ((int) $taxId[$position]) * $weight;
        }

        $remainder = 11 - ($sum % 11);
        $checkDigit = match ($remainder) {
            11 => 0,
            10 => 9,
            default => $remainder,
        };

        return $checkDigit === (int) $taxId[10];
    }
}
