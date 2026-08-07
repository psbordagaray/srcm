<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Domain\Tenancy\OrganizationMemberManager;
use App\Enums\UserRole;
use App\Models\OrganizationMembership;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationMemberController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organization = $currentOrganization->get(
            $request->user()
        );

        $memberships = OrganizationMembership::query()
            ->with([
                'user' => fn ($query) =>
                    $query->withTrashed(),
            ])
            ->where(
                'organization_id',
                $organization->getKey()
            )
            ->orderByDesc('active')
            ->orderByRaw(
                "CASE role "
                ."WHEN 'admin' THEN 0 "
                ."WHEN 'operator' THEN 1 "
                ."ELSE 2 END"
            )
            ->orderBy('id')
            ->get();

        return view(
            'organization.members',
            [
                'organization' => $organization,
                'memberships' => $memberships,
                'roles' => UserRole::cases(),
            ]
        );
    }

    public function store(
        Request $request,
        OrganizationMemberManager $manager
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'role' => [
                'required',
                Rule::enum(UserRole::class),
            ],
        ]);

        try {
            $membership = $manager->provision(
                $validated,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput(
                    $request->except([
                        'password',
                        'password_confirmation',
                    ])
                )
                ->with(
                    'error',
                    $exception->getMessage()
                );
        }

        return redirect()
            ->route('organization-members.index')
            ->with(
                'success',
                'Acceso configurado para '
                .$membership->user?->email.'.'
            );
    }

    public function updateRole(
        Request $request,
        int $membership,
        OrganizationMemberManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $validated = $request->validate([
            'role' => [
                'required',
                Rule::enum(UserRole::class),
            ],
        ]);

        $target = $this->membership(
            $membership,
            $request,
            $currentOrganization
        );

        try {
            $manager->changeRole(
                $target,
                UserRole::from($validated['role']),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        }

        return back()->with(
            'success',
            'Rol actualizado.'
        );
    }

    public function toggleActive(
        Request $request,
        int $membership,
        OrganizationMemberManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $target = $this->membership(
            $membership,
            $request,
            $currentOrganization
        );

        try {
            $updated = $manager->toggleActive(
                $target,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        }

        return back()->with(
            'success',
            $updated->active
                ? 'Acceso reactivado.'
                : 'Acceso desactivado.'
        );
    }

    private function membership(
        int $membership,
        Request $request,
        CurrentOrganization $currentOrganization
    ): OrganizationMembership {
        return OrganizationMembership::query()
            ->whereKey($membership)
            ->where(
                'organization_id',
                $currentOrganization->id(
                    $request->user()
                )
            )
            ->firstOrFail();
    }
}
