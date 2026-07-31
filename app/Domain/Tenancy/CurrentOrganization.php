<?php

namespace App\Domain\Tenancy;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;

class CurrentOrganization
{
    /**
     * @var array<int, Organization|null>
     */
    private array $organizationCache = [];

    /**
     * @var array<string, OrganizationMembership|null>
     */
    private array $membershipCache = [];

    public function getOrNull(?User $user = null): ?Organization
    {
        $user ??= auth()->user();

        if (! $user) {
            return null;
        }

        $userId = (int) $user->getKey();

        if (array_key_exists($userId, $this->organizationCache)) {
            return $this->organizationCache[$userId];
        }

        $membership = $this->resolveMembership($user);

        $organization = $membership?->organization;

        $this->organizationCache[$userId] = $organization;

        return $organization;
    }

    public function get(?User $user = null): Organization
    {
        return $this->getOrNull($user)
            ?? throw new DomainException(
                'El usuario no posee una organización activa.'
            );
    }

    public function idOrNull(?User $user = null): ?int
    {
        return $this->getOrNull($user)?->getKey();
    }

    public function id(?User $user = null): int
    {
        return (int) $this->get($user)->getKey();
    }

    public function membershipFor(
        User $user,
        ?Organization $organization = null
    ): ?OrganizationMembership {
        $organization ??= $this->getOrNull($user);

        if (! $organization) {
            return null;
        }

        $cacheKey = $user->getKey().':'.$organization->getKey();

        if (array_key_exists($cacheKey, $this->membershipCache)) {
            return $this->membershipCache[$cacheKey];
        }

        $membership = OrganizationMembership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('active', true)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('active', true)
            )
            ->first();

        $this->membershipCache[$cacheKey] = $membership;

        return $membership;
    }

    public function roleFor(User $user): ?UserRole
    {
        return $this->membershipFor($user)?->role;
    }

    public function switchTo(
        User $user,
        Organization $organization
    ): Organization {
        $membership = $this->membershipFor(
            $user,
            $organization
        );

        if (! $membership) {
            throw new DomainException(
                'No posee una membresía activa en esa organización.'
            );
        }

        $user->forceFill([
            'current_organization_id' => $organization->getKey(),
        ])->saveQuietly();

        $this->forget($user);

        return $organization;
    }

    public function forget(User $user): void
    {
        unset($this->organizationCache[(int) $user->getKey()]);

        foreach (array_keys($this->membershipCache) as $key) {
            if (str_starts_with($key, $user->getKey().':')) {
                unset($this->membershipCache[$key]);
            }
        }
    }

    private function resolveMembership(
        User $user
    ): ?OrganizationMembership {
        $query = OrganizationMembership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->whereHas(
                'organization',
                fn ($organizationQuery) =>
                    $organizationQuery->where('active', true)
            );

        $membership = $user->current_organization_id
            ? (clone $query)
                ->where(
                    'organization_id',
                    $user->current_organization_id
                )
                ->first()
            : null;

        $membership ??= $query
            ->orderBy('organization_id')
            ->first();

        if (
            $membership
            && (int) $user->current_organization_id
                !== (int) $membership->organization_id
        ) {
            $user->forceFill([
                'current_organization_id' =>
                    $membership->organization_id,
            ])->saveQuietly();
        }

        if ($membership) {
            $key = $user->getKey().':'.$membership->organization_id;
            $this->membershipCache[$key] = $membership;
        }

        return $membership;
    }
}
