<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePostSaleResolution;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteCommercePostSaleCashRefund extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'execute-commerce-post-sale-cash-refund'
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' =>
                trim(
                    (string) $this->input(
                        'idempotency_key'
                    )
                ),
            'execution_reference' =>
                filled(
                    $this->input(
                        'execution_reference'
                    )
                )
                    ? trim(
                        (string) $this->input(
                            'execution_reference'
                        )
                    )
                    : null,
            'execution_note' =>
                filled(
                    $this->input(
                        'execution_note'
                    )
                )
                    ? trim(
                        (string) $this->input(
                            'execution_note'
                        )
                    )
                    : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
            'execution_reference' => [
                'nullable',
                'string',
                'max:180',
            ],
            'execution_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
