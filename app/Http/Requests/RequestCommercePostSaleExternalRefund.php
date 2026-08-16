<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePostSaleResolution;
use Illuminate\Foundation\Http\FormRequest;

class RequestCommercePostSaleExternalRefund extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'execute-commerce-post-sale-external-refund'
            )
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
}
