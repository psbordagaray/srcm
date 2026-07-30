<?php

namespace App\Http\Requests;

use App\Enums\CompatibilityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompatibilityRequest extends FormRequest
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
            'related_entity_uuid' => [
                'required',
                'uuid',
                Rule::exists('entities', 'uuid')
                    ->where('active', true),
            ],
            'relationship_type' => [
                'required',
                'string',
                Rule::in(
                    array_map(
                        fn (CompatibilityType $type): string =>
                            $type->value,
                        CompatibilityType::cases()
                    )
                ),
            ],
            'confidence' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],
            'source' => [
                'nullable',
                'string',
                'max:255',
            ],
            'evidence' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'related_entity_uuid' => trim(
                (string) $this->input('related_entity_uuid')
            ),
            'relationship_type' => trim(
                (string) $this->input('relationship_type')
            ),
            'confidence' => $this->input('confidence', 80),
            'source' => $this->nullableTrimmed('source'),
            'evidence' => $this->nullableTrimmed('evidence'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'related_entity_uuid.required' =>
                'Buscá y seleccioná una entidad relacionada.',
            'related_entity_uuid.uuid' =>
                'La entidad seleccionada no tiene un identificador válido.',
            'related_entity_uuid.exists' =>
                'La entidad seleccionada no existe o está inactiva.',

            'relationship_type.required' =>
                'Seleccioná el tipo de relación.',
            'relationship_type.in' =>
                'El tipo de relación seleccionado no está permitido.',

            'confidence.required' =>
                'Indicá el nivel de confianza.',
            'confidence.integer' =>
                'La confianza debe ser un número entero.',
            'confidence.min' =>
                'La confianza mínima es 1%.',
            'confidence.max' =>
                'La confianza máxima es 100%.',

            'source.max' =>
                'La fuente no puede superar los 255 caracteres.',
            'evidence.max' =>
                'La evidencia no puede superar los 2000 caracteres.',
        ];
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === ''
            ? null
            : $value;
    }
}
