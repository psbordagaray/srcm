<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecutePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'execute-purchase-payments'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'execution_reference' => filled(
                $this->input('execution_reference')
            )
                ? trim((string) $this->input('execution_reference'))
                : null,
            'execution_note' => filled(
                $this->input('execution_note')
            )
                ? trim((string) $this->input('execution_note'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'confirm_execute' => [
                'required',
                'accepted',
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
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^purchase-ui:payment-execute:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
