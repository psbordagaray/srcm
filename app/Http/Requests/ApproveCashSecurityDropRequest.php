<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveCashSecurityDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve-cash-security-drop')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'approval_note' => trim(
                (string) $this->input('approval_note')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'approval_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^cash-ui:security-drop-approve:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
