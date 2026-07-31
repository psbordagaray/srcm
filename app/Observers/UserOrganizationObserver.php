<?php

namespace App\Observers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use LogicException;

class UserOrganizationObserver
{
    public function created(User $user): void
    {
        $organizations = Organization::query()
            ->where('active', true)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($organizations->count() !== 1) {
            return;
        }

        $organization = $organizations->first();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->firstOrCreate(
                    [
                        'organization_id' => $organization->getKey(),
                        'user_id' => $user->getKey(),
                    ],
                    [
                        'role' => $user->role->value,
                        'active' => true,
                    ]
                )
        );

        $user->forceFill([
            'current_organization_id' => $organization->getKey(),
        ])->saveQuietly();
    }

    public function deleting(User $user): void
    {
        if ($user->isForceDeleting()) {
            throw new LogicException(
                'Los usuarios no pueden eliminarse físicamente.'
            );
        }

        OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->update([
                'active' => false,
                'updated_at' => now(),
            ]);
    }
}
