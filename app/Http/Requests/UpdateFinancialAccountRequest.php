<?php

namespace App\Http\Requests;

use App\Enums\FinancialAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-financial-accounts')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'type' => strtolower(trim((string) $this->input('type'))),
            'currency_code' => strtoupper(trim(
                (string) $this->input('currency_code')
            )),
            'provider' => $this->optional(
                (string) $this->input('provider')
            ),
            'external_label' => $this->optional(
                (string) $this->input('external_label')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => [
                'required',
                Rule::enum(FinancialAccountType::class),
            ],
            'currency_code' => [
                'required',
                'string',
                'regex:/^[A-Z]{3}$/',
            ],
            'provider' => ['nullable', 'string', 'max:100'],
            'external_label' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_code.regex' =>
                'La moneda debe expresarse con tres letras, por ejemplo ARS o USD.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
