<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function show(
        CurrentOrganization $currentOrganization
    ): View {
        $organization = $currentOrganization
            ->get()
            ->load([
                'memberships' => fn ($query) => $query
                    ->with('user')
                    ->orderByDesc('active')
                    ->orderBy('id'),
            ]);

        $memberships = auth()->user()
            ->organizationMemberships()
            ->with('organization')
            ->where('active', true)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('active', true)
            )
            ->orderBy('organization_id')
            ->get();

        return view('organizations.show', compact(
            'organization',
            'memberships'
        ));
    }

    public function edit(
        CurrentOrganization $currentOrganization
    ): View {
        return view('organizations.edit', [
            'organization' => $currentOrganization->get(),
        ]);
    }

    public function update(
        UpdateOrganizationRequest $request,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $organization = $currentOrganization->get();

        $organization->update($request->validated());

        return redirect()
            ->route('organization.show')
            ->with(
                'success',
                'Organización actualizada correctamente.'
            );
    }

    public function activate(
        Request $request,
        Organization $organization,
        CurrentOrganization $currentOrganization,
        AuditRecorder $auditRecorder
    ): RedirectResponse {
        $previousOrganization = $currentOrganization->get();

        try {
            $currentOrganization->switchTo(
                $request->user(),
                $organization
            );
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        $auditRecorder->record(
            $organization,
            'organization_switched',
            [
                'organization_id' =>
                    $previousOrganization->getKey(),
                'organization_name' =>
                    $previousOrganization->name,
            ],
            [
                'organization_id' => $organization->getKey(),
                'organization_name' => $organization->name,
            ]
        );

        return redirect()
            ->route('dashboard')
            ->with(
                'success',
                "Organización activa: {$organization->name}."
            );
    }
}
