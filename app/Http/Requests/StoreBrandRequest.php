<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesOptionalWebsite;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
{
    use NormalizesOptionalWebsite;

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
            'logo' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
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
            'website' => $this->normalizeOptionalWebsite(
                $this->input('website')
            ),
            'active' => $this->boolean('active'),
        ]);
    }

    /**
     * Mensajes personalizados.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la marca es obligatorio.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',

            'logo.max' => 'La referencia del logotipo no puede superar los 255 caracteres.',

            'website.url' => 'El sitio web debe ser una URL válida.',
            'website.max' => 'El sitio web no puede superar los 255 caracteres.',
        ];
    }
}
