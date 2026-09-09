<?php

namespace App\Domain\Catalog;

use App\Models\Organization;
use App\Models\OrganizationCapabilityPolicyBinding;
use App\Models\SemanticCapabilityDefinition;
use Illuminate\Support\Facades\DB;

class OrganizationCapabilityPolicyManager
{
    public function set(
        Organization $organization,
        SemanticCapabilityDefinition $capability,
        bool $enabled
    ): OrganizationCapabilityPolicyBinding {
        return DB::transaction(function () use (
            $organization,
            $capability,
            $enabled
        ): OrganizationCapabilityPolicyBinding {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCapability = SemanticCapabilityDefinition::query()
                ->whereKey($capability->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $binding = OrganizationCapabilityPolicyBinding::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where(
                    'semantic_capability_definition_id',
                    $lockedCapability->id
                )
                ->lockForUpdate()
                ->first();

            if ($binding) {
                $binding->enabled = $enabled;
                $binding->save();

                return $binding->fresh();
            }

            return OrganizationCapabilityPolicyBinding::query()->create([
                'organization_id' => $lockedOrganization->id,
                'semantic_capability_definition_id' =>
                    $lockedCapability->id,
                'enabled' => $enabled,
            ])->fresh();
        });
    }

    public function reset(
        Organization $organization,
        SemanticCapabilityDefinition $capability
    ): void {
        DB::transaction(function () use (
            $organization,
            $capability
        ): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCapability = SemanticCapabilityDefinition::query()
                ->whereKey($capability->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $binding = OrganizationCapabilityPolicyBinding::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where(
                    'semantic_capability_definition_id',
                    $lockedCapability->id
                )
                ->lockForUpdate()
                ->first();

            if ($binding) {
                $binding->delete();
            }
        });
    }
}
