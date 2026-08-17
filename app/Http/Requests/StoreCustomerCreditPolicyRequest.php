<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerCreditPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'manage-customer-credit-policies'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency_code' => strtoupper(trim(
                (string) $this->input(
                    'currency_code',
                    'ARS'
                )
            )),
            'limit' => str_replace(
                ',',
                '.',
                trim((string) $this->input('limit', ''))
            ),
            'reason' => trim(
                (string) $this->input('reason', '')
            ),
            'idempotency_key' => trim(
                (string) $this->input(
                    'idempotency_key',
                    ''
                )
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'currency_code' => [
                'required',
                'string',
                Rule::in(['ARS', 'USD']),
            ],
            'limit' => [
                'required',
                'string',
                'max:18',
                'regex:/^\d{1,14}(?:[.,]\d{1,2})?$/',
            ],
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
                'regex:/^customer-credit-policy-ui:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'limit.regex' =>
                'El límite debe ser un importe mayor o igual a cero con hasta dos decimales.',
            'reason.required' =>
                'El cambio de límite requiere un motivo administrativo.',
        ];
    }
}
