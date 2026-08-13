<?php

namespace App\Http\Requests;

use App\Enums\CashCountDifferenceReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operate-cash-register')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'counted_amount' => trim(
                (string) $this->input('counted_amount')
            ),
            'difference_reason' => trim(
                (string) $this->input('difference_reason')
            ),
            'closing_note' => trim(
                (string) $this->input('closing_note')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'operation' => 'close',
        ]);
    }

    public function rules(): array
    {
        return [
            'counted_amount' => [
                'required',
                'string',
                'max:18',
                'regex:/^\d{1,14}(?:[.,]\d{1,2})?$/',
            ],
            'difference_reason' => [
                'nullable',
                Rule::enum(CashCountDifferenceReason::class),
            ],
            'closing_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'confirm_close' => [
                'accepted',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^cash-ui:close:[0-9a-f-]{36}$/',
            ],
            'closed_at' => [
                'prohibited',
            ],
            'operation' => [
                'required',
                'in:close',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'counted_amount.regex' =>
                'El efectivo contado debe ser un importe válido.',
            'confirm_close.accepted' =>
                'Debés confirmar explícitamente el cierre del turno.',
            'closed_at.prohibited' =>
                'La hora de cierre la determina SRCM.',
        ];
    }
}
