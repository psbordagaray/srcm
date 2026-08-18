<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPurchasePaymentExternalMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'review-financial-reconciliation'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'note' => filled($this->input('note'))
                ? trim((string) $this->input('note'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'confirm_verify' => [
                'required',
                'accepted',
            ],
            'note' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
                'regex:/^purchase-ui:payment-external-verify:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
