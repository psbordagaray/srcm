<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier_type_id' => [
                'required',
                'integer',
                Rule::exists('identifier_types', 'id')
                    ->where('active', true),
            ],
            'identifier_value' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifier_value' => trim(
                (string) $this->input('identifier_value')
            ),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier_type_id.required' =>
                'Seleccioná un tipo de identificador.',
            'identifier_type_id.exists' =>
                'El tipo de identificador no existe o está inactivo.',
            'identifier_value.required' =>
                'El código o identificador es obligatorio.',
            'identifier_value.max' =>
                'El identificador no puede superar los 255 caracteres.',
        ];
    }
}
