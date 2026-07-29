<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductCategoryRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    /**
     * Reglas de validación.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Normaliza los datos antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->boolean('active'),
        ]);
    }

    /**
     * Mensajes personalizados.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.max'      => 'El nombre no puede superar los 100 caracteres.',
            'icon.max'      => 'El nombre del ícono no puede superar los 60 caracteres.',
        ];
    }
}
