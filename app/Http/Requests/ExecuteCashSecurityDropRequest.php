<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteCashSecurityDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('execute-cash-security-drop')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'confirm_execute' => [
                'accepted',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^cash-ui:security-drop-execute:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_execute.accepted' =>
                'Debés confirmar la extracción física del efectivo.',
        ];
    }
}
