<?php

namespace App\Http\Controllers;

use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Http\Requests\SaveFiscalOrganizationProfileRequest;
use App\Http\Requests\StoreFiscalPointOfSaleRequest;
use App\Models\FiscalPointOfSale;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FiscalConfigurationController extends Controller
{
    public function index(
        CurrentOrganization $currentOrganization
    ): View {
        $organization = $currentOrganization->get();
        $profile = $organization->fiscalProfile()
            ->with('pointsOfSale')
            ->first();

        return view('fiscal.configuration', [
            'organization' => $organization,
            'profile' => $profile,
            'environments' => FiscalEnvironment::cases(),
            'integrationModes' => FiscalIntegrationMode::cases(),
        ]);
    }

    public function updateProfile(
        SaveFiscalOrganizationProfileRequest $request,
        FiscalOrganizationProfileManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $manager->save(
                new FiscalOrganizationProfileData(
                    legalName: $validated['legal_name'],
                    taxId: $validated['tax_id'],
                    vatConditionCode: $validated['vat_condition_code'],
                    grossIncomeNumber:
                        $validated['gross_income_number'] ?? null,
                    activityStartedOn:
                        $validated['activity_started_on'],
                    addressLine: $validated['address_line'],
                    city: $validated['city'],
                    provinceCode: $validated['province_code'],
                    postalCode: $validated['postal_code']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['fiscal_profile' => $exception->getMessage()]);
        }

        return redirect()
            ->route('fiscal-configuration.index')
            ->with('success', 'Perfil fiscal actualizado correctamente.');
    }

    public function storePoint(
        StoreFiscalPointOfSaleRequest $request,
        FiscalPointOfSaleManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $manager->create(
                new FiscalPointOfSaleData(
                    pointNumber: (int) $validated['point_number'],
                    environment: FiscalEnvironment::from(
                        $validated['environment']
                    ),
                    integrationMode: FiscalIntegrationMode::from(
                        $validated['integration_mode']
                    )
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['fiscal_point' => $exception->getMessage()]);
        }

        return redirect()
            ->route('fiscal-configuration.index')
            ->with('success', 'Punto de venta fiscal registrado.');
    }

    public function togglePoint(
        FiscalPointOfSale $fiscalPointOfSale,
        FiscalPointOfSaleManager $manager
    ): RedirectResponse {
        try {
            $manager->toggleActive(
                $fiscalPointOfSale,
                request()->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'fiscal_point' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('fiscal-configuration.index')
            ->with('success', 'Estado del punto de venta actualizado.');
    }
}

