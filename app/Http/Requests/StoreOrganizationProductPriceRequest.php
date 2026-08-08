<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationProductPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-commerce-prices')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency_code' => strtoupper(trim(
                (string) $this->input('currency_code')
            )),
            'amount' => str_replace(
                ',',
                '.',
                trim((string) $this->input('amount'))
            ),
            'reason' => filled($this->input('reason'))
                ? trim((string) $this->input('reason'))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'currency_code' => [
                'required',
                Rule::in(['ARS', 'USD']),
            ],
            'amount' => [
                'required',
                'string',
                'max:18',
                'regex:/^(?=.*[1-9])\d{1,14}(?:\.\d{1,2})?$/',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_code.in' => 'La moneda debe ser ARS o USD.',
            'amount.regex' => 'El precio debe ser mayor que cero y admitir hasta dos decimales.',
        ];
    }
}
