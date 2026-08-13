<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestPurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'request-purchase-payments'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'origin_financial_account_id' => ctype_digit(
                (string) $this->input(
                    'origin_financial_account_id'
                )
            )
                ? (int) $this->input(
                    'origin_financial_account_id'
                )
                : null,
            'amount' => trim(
                (string) $this->input('amount')
            ),
            'request_note' => filled(
                $this->input('request_note')
            )
                ? trim((string) $this->input('request_note'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'origin_financial_account_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'request_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^purchase-ui:payment-request:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
