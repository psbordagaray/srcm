<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePostSaleExternalRefundInstruction;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCommercePostSaleExternalRefund extends FormRequest
{
    public function authorize(): bool
    {
        $user =
            $this->user();

        if (
            ! $user
            || ! $user->can(
                'dispatch-commerce-post-sale-external-refund'
            )
        ) {
            return false;
        }

        $instruction =
            $this->route(
                'externalRefundInstruction'
            );

        if (
            ! $instruction
                instanceof CommercePostSaleExternalRefundInstruction
        ) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $instruction
                ->organization_id
                === $organizationId,
            404
        );

        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_submission' => [
                'accepted',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_submission.accepted' =>
                'Confirmá explícitamente el envío real del reembolso al proveedor.',
        ];
    }
}
