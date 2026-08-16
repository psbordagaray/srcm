<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePostSaleResolution;
use Illuminate\Foundation\Http\FormRequest;

class MaterializeCommercePostSaleCustomerCredit extends FormRequest
{
    public function authorize(): bool
    {
        return $this->authorizeResolution(
            'materialize-commerce-post-sale-customer-credit'
        );
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
        ];
    }

    private function authorizeResolution(
        string $ability
    ): bool {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can($ability)
        ) {
            return false;
        }

        $resolution =
            $this->route(
                'commercePostSaleResolution'
            );

        if (
            ! $resolution
                instanceof CommercePostSaleResolution
        ) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $resolution
                ->organization_id
                === $organizationId,
            404
        );

        return true;
    }
}
