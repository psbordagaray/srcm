<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolvePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (
            $this->user()?->can(
                'request-purchase-payments'
            )
            || $this->user()?->can(
                'approve-purchase-payments'
            )
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'resolution_note' => trim(
                (string) $this->input('resolution_note')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'resolution_note' => [
                'required',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^purchase-ui:payment-resolution:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
