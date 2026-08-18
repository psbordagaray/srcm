<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePurchasePaymentGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'approve-purchase-payments'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'approval_note' => filled(
                $this->input('approval_note')
            )
                ? trim((string) $this->input('approval_note'))
                : null,
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
                'regex:/^purchase-ui:payment-group-approve:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
