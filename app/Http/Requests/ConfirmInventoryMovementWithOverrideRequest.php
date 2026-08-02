<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmInventoryMovementWithOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $movement = $this->route('inventoryMovement');

        if (! $user || ! $movement instanceof InventoryMovement) {
            return false;
        }

        $role = app(CurrentOrganization::class)->roleFor($user);

        return ($role?->canRequestInventoryNegative() ?? false)
            && ($role?->canConfirmInventoryMovement(
                $movement->type
            ) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
