<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFiscalOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-organization') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'legal_name' => trim((string) $this->input('legal_name')),
            'tax_id' => preg_replace(
                '/\D+/',
                '',
                (string) $this->input('tax_id')
            ),
            'vat_condition_code' => trim(
                (string) $this->input('vat_condition_code')
            ),
            'gross_income_number' => filled(
                $this->input('gross_income_number')
            )
                ? trim((string) $this->input('gross_income_number'))
                : null,
            'address_line' => trim(
                (string) $this->input('address_line')
            ),
            'city' => trim((string) $this->input('city')),
            'province_code' => strtoupper(trim(
                (string) $this->input('province_code')
            )),
            'postal_code' => strtoupper(trim(
                (string) $this->input('postal_code')
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:191'],
            'tax_id' => ['required', 'regex:/^\d{11}$/D'],
            'vat_condition_code' => [
                'required',
                'regex:/^\d{1,10}$/D',
            ],
            'gross_income_number' => [
                'nullable',
                'string',
                'max:50',
            ],
            'activity_started_on' => [
                'required',
                'date_format:Y-m-d',
            ],
            'address_line' => ['required', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:191'],
            'province_code' => [
                'required',
                'regex:/^[A-Z0-9-]{1,10}$/D',
            ],
            'postal_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_id.regex' => 'El CUIT debe contener exactamente 11 dígitos.',
            'vat_condition_code.regex' =>
                'La condición IVA debe usar el código vigente de ARCA.',
            'province_code.regex' =>
                'El código de provincia no es válido.',
        ];
    }
}

