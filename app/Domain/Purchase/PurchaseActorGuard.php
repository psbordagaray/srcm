<?php

namespace App\Domain\Purchase;

use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PurchaseActorGuard
{
    public function authorize(
        User $actor,
        string $ability
    ): int {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first(['id']);

        if (! $organization) {
            throw new DomainException(
                'La organización activa no existe o está inactiva.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership) {
            throw new DomainException(
                'El usuario no posee una membresía activa en la organización.'
            );
        }

        $allowed = match ($ability) {
            'view' => $membership->role->canViewPurchases(),
            'draft' => $membership->role->canDraftPurchaseOrders(),
            'issue' => $membership->role->canIssuePurchaseOrders(),
            'receive' => $membership->role->canReceivePurchases(),
            'obligate' => $membership->role->canCreatePurchaseObligations(),
            'request-payment' => $membership->role->canRequestPurchasePayments(),
            'approve-payment' => $membership->role->canApprovePurchasePayments(),
            'execute-payment' => $membership->role->canExecutePurchasePayments(),
            'cancel' => $membership->role->canCancelPurchaseOrders(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El rol del usuario no posee la facultad requerida en Compras.'
            );
        }

        return $organizationId;
    }
}
