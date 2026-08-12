<?php

namespace App\Http\Requests;

use App\Enums\CashSecurityDropReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCashSecurityDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operate-cash-register')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => trim((string) $this->input('amount')),
            'reason_code' => trim(
                (string) $this->input('reason_code')
            ),
            'note' => trim((string) $this->input('note')),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'destination_financial_account_id' => [
                'required',
                'integer',
                'exists:financial_accounts,id',
            ],
            'amount' => [
                'required',
                'string',
                'max:18',
                'regex:/^\d{1,14}(?:[.,]\d{1,2})?$/',
            ],
            'reason_code' => [
                'required',
                Rule::enum(CashSecurityDropReason::class),
            ],
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^cash-ui:security-drop:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' =>
                'El retiro debe ser un importe válido mayor que cero.',
        ];
    }
}
