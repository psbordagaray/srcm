<?php

namespace App\Http\Requests;

use App\Enums\PurchasePaymentExternalResolutionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePurchasePaymentExternalDifferenceRequest extends FormRequest
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
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'note' => trim((string) $this->input('note')),
        ]);
    }

    public function rules(): array
    {
        return [
            'confirm_resolve' => ['required', 'accepted'],
            'outcome' => [
                'required',
                Rule::enum(
                    PurchasePaymentExternalResolutionOutcome::class
                ),
            ],
            'note' => [
                'required',
                'string',
                'min:10',
                'max:2000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
                'regex:/^purchase-ui:payment-external-resolve:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
