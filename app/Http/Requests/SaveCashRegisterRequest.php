<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-cash-registers')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'financial_account_id' => filled(
                $this->input('financial_account_id')
            )
                ? (int) $this->input('financial_account_id')
                : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id(
            $this->user()
        );

        return [
            'name' => [
                'required',
                'string',
                'max:120',
            ],
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true)
                    ->where(
                        'type',
                        FinancialAccountType::CashBox->value
                    ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'financial_account_id.exists' =>
                'La cuenta debe ser una caja de efectivo activa de la organización.',
        ];
    }
}
