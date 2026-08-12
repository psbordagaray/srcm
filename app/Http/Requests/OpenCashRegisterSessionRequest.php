<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operate-cash-register')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'opening_amount' => trim(
                (string) $this->input('opening_amount', '0')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'opening_amount' => [
                'required',
                'string',
                'max:18',
                'regex:/^\d{1,14}(?:[.,]\d{1,2})?$/',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^cash-ui:open:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'opening_amount.regex' =>
                'El fondo inicial debe ser un importe válido, igual o mayor que cero.',
        ];
    }
}
