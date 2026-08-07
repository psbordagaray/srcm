<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditRecorder;
use App\Enums\UserRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationMemberManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    /**
     * @param array{
     *   name?: string|null,
     *   email: string,
     *   password?: string|null,
     *   role: string|UserRole
     * } $data
     */
    public function provision(
        array $data,
        User $actor
    ): OrganizationMembership {
        $organization = $this->currentOrganization->get($actor);
        $this->assertManager($actor);

        $email = Str::lower(
            Str::of((string) $data['email'])
                ->squish()
                ->toString()
        );
        $role = $this->role($data['role']);

        if ($email === '') {
            throw new DomainException(
                'El correo electrónico es obligatorio.'
            );
        }

        return DB::transaction(function () use (
            $organization,
            $actor,
            $data,
            $email,
            $role
        ): OrganizationMembership {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($user?->trashed()) {
                throw new DomainException(
                    'La cuenta existe pero está desactivada globalmente.'
                );
            }

            $createdUser = false;

            if (! $user) {
                $name = Str::of(
                    (string) ($data['name'] ?? '')
                )->squish()->toString();
                $password = (string) (
                    $data['password'] ?? ''
                );

                if ($name === '') {
                    throw new DomainException(
                        'El nombre es obligatorio para un usuario nuevo.'
                    );
                }

                if (Str::length($password) < 8) {
                    throw new DomainException(
                        'La contraseña inicial debe tener al menos 8 caracteres.'
                    );
                }

                $user = User::withoutEvents(
                    function () use (
                        $name,
                        $email,
                        $password
                    ): User {
                        $created = new User();

                        $created->forceFill([
                            'name' => $name,
                            'email' => $email,
                            'password' => $password,
                            'email_verified_at' => now(),
                            'role' => UserRole::Viewer->value,
                            'current_organization_id' => null,
                        ]);

                        $created->save();

                        return $created;
                    }
                );

                $createdUser = true;
            }

            $membership = OrganizationMembership::query()
                ->where(
                    'organization_id',
                    $organization->getKey()
                )
                ->where('user_id', $user->getKey())
                ->first();

            if ($membership?->active) {
                throw new DomainException(
                    'Ese usuario ya posee acceso activo a la organización.'
                );
            }

            $event = $membership
                ? 'membership_reactivated'
                : 'membership_created';

            if (! $membership) {
                $membership = OrganizationMembership::withoutEvents(
                    fn () => OrganizationMembership::query()
                        ->create([
                            'organization_id' =>
                                $organization->getKey(),
                            'user_id' => $user->getKey(),
                            'role' => $role->value,
                            'active' => true,
                        ])
                );
            } else {
                OrganizationMembership::withoutEvents(
                    function () use (
                        $membership,
                        $role
                    ): void {
                        $membership->forceFill([
                            'role' => $role->value,
                            'active' => true,
                        ])->save();
                    }
                );
            }

            if (
                $createdUser
                || $user->current_organization_id === null
            ) {
                $user->forceFill([
                    'current_organization_id' =>
                        $organization->getKey(),
                ])->saveQuietly();
            }

            $this->currentOrganization->forget($user);

            $this->audit->record(
                $membership,
                $event,
                null,
                [
                    'user_id' => $user->getKey(),
                    'user_email' => $user->email,
                    'role' => $role,
                    'active' => true,
                    'created_user' => $createdUser,
                ]
            );

            return $membership
                ->refresh()
                ->load('user');
        });
    }

    public function changeRole(
        OrganizationMembership $membership,
        UserRole $role,
        User $actor
    ): OrganizationMembership {
        $membership = $this->scopedMembership(
            $membership,
            $actor
        );
        $this->assertManager($actor);

        if (
            (int) $membership->user_id
            === (int) $actor->getKey()
            && $membership->role !== $role
        ) {
            throw new DomainException(
                'No podés cambiar tu propio rol administrativo.'
            );
        }

        if (
            $membership->active
            && $membership->role === UserRole::Admin
            && $role !== UserRole::Admin
        ) {
            $this->assertAnotherActiveAdmin(
                $membership
            );
        }

        if ($membership->role === $role) {
            return $membership;
        }

        $oldRole = $membership->role;

        OrganizationMembership::withoutEvents(
            function () use (
                $membership,
                $role
            ): void {
                $membership->forceFill([
                    'role' => $role->value,
                ])->save();
            }
        );

        $target = $membership->user;

        if ($target) {
            $this->currentOrganization->forget($target);
        }

        $this->audit->record(
            $membership,
            'membership_role_changed',
            [
                'role' => $oldRole,
                'active' => $membership->active,
            ],
            [
                'role' => $role,
                'active' => $membership->active,
            ]
        );

        return $membership->refresh()->load('user');
    }

    public function toggleActive(
        OrganizationMembership $membership,
        User $actor
    ): OrganizationMembership {
        $membership = $this->scopedMembership(
            $membership,
            $actor
        );
        $this->assertManager($actor);

        if (
            (int) $membership->user_id
            === (int) $actor->getKey()
        ) {
            throw new DomainException(
                'No podés desactivar tu propio acceso.'
            );
        }

        $nextActive = ! $membership->active;

        if (
            ! $nextActive
            && $membership->role === UserRole::Admin
        ) {
            $this->assertAnotherActiveAdmin(
                $membership
            );
        }

        $target = $membership->user;

        if (! $target) {
            throw new DomainException(
                'La cuenta asociada a la membresía no está disponible.'
            );
        }

        if ($target->trashed() && $nextActive) {
            throw new DomainException(
                'No se puede reactivar una membresía de una cuenta eliminada.'
            );
        }

        OrganizationMembership::withoutEvents(
            function () use (
                $membership,
                $nextActive
            ): void {
                $membership->forceFill([
                    'active' => $nextActive,
                ])->save();
            }
        );

        $organizationId = (int) $membership->organization_id;

        if (
            ! $nextActive
            && (int) $target->current_organization_id
                === $organizationId
        ) {
            $fallback = OrganizationMembership::query()
                ->where('user_id', $target->getKey())
                ->where('active', true)
                ->where(
                    'organization_id',
                    '!=',
                    $organizationId
                )
                ->whereHas(
                    'organization',
                    fn ($query) =>
                        $query->where('active', true)
                )
                ->orderBy('organization_id')
                ->first();

            $target->forceFill([
                'current_organization_id' =>
                    $fallback?->organization_id,
            ])->saveQuietly();
        } elseif (
            $nextActive
            && $target->current_organization_id === null
        ) {
            $target->forceFill([
                'current_organization_id' =>
                    $organizationId,
            ])->saveQuietly();
        }

        $this->currentOrganization->forget($target);

        $this->audit->record(
            $membership,
            $nextActive
                ? 'membership_reactivated'
                : 'membership_deactivated',
            [
                'role' => $membership->role,
                'active' => ! $nextActive,
            ],
            [
                'role' => $membership->role,
                'active' => $nextActive,
            ]
        );

        return $membership->refresh()->load('user');
    }

    private function assertManager(User $actor): void
    {
        $role = $this->currentOrganization
            ->roleFor($actor);

        if (! $role?->canManageOrganizationMembers()) {
            throw new DomainException(
                'No posee permiso para administrar usuarios de esta organización.'
            );
        }
    }

    private function scopedMembership(
        OrganizationMembership $membership,
        User $actor
    ): OrganizationMembership {
        $organizationId = $this->currentOrganization
            ->id($actor);

        return OrganizationMembership::query()
            ->with([
                'user' => fn ($query) =>
                    $query->withTrashed(),
            ])
            ->whereKey($membership->getKey())
            ->where(
                'organization_id',
                $organizationId
            )
            ->firstOrFail();
    }

    private function assertAnotherActiveAdmin(
        OrganizationMembership $membership
    ): void {
        $count = OrganizationMembership::query()
            ->where(
                'organization_id',
                $membership->organization_id
            )
            ->where('active', true)
            ->where('role', UserRole::Admin->value)
            ->whereKeyNot($membership->getKey())
            ->count();

        if ($count < 1) {
            throw new DomainException(
                'La organización debe conservar al menos un administrador activo.'
            );
        }
    }

    private function role(
        string|UserRole $role
    ): UserRole {
        if ($role instanceof UserRole) {
            return $role;
        }

        return UserRole::tryFrom($role)
            ?? throw new DomainException(
                'El rol seleccionado no es válido.'
            );
    }
}
