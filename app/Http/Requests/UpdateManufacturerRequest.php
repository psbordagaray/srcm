<?php

namespace App\Http\Requests;

use App\Models\Manufacturer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateManufacturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = Str::of(
            (string) $this->input('name')
        )->squish()->toString();

        $website = trim(
            (string) $this->input('website')
        );

        if (
            $website !== ''
            && ! Str::startsWith(
                $website,
                ['http://', 'https://']
            )
        ) {
            $website = 'https://'.$website;
        }

        $this->merge([
            'name' => $name,
            'website' => $website === ''
                ? null
                : $website,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'website' => [
                'nullable',
                'string',
                'max:2048',
                'url:http,https',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $manufacturer = $this->route('manufacturer');

            $normalizedName = Manufacturer::normalizeName(
                (string) $this->input('name')
            );

            $duplicateExists = Manufacturer::query()
                ->when(
                    $manufacturer instanceof Manufacturer,
                    fn ($query) => $query->whereKeyNot(
                        $manufacturer->getKey()
                    )
                )
                ->where(
                    'normalized_name',
                    $normalizedName
                )
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'name',
                    'Ya existe un fabricante con este nombre o una variante equivalente.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' =>
                'El nombre del fabricante es obligatorio.',
            'name.max' =>
                'El nombre no puede superar los 255 caracteres.',
            'website.url' =>
                'El sitio web debe ser una dirección válida.',
            'website.max' =>
                'El sitio web no puede superar los 2048 caracteres.',
            'description.max' =>
                'La descripción no puede superar los 5000 caracteres.',
            'active.required' =>
                'El estado es obligatorio.',
            'active.boolean' =>
                'El estado seleccionado no es válido.',
        ];
    }
}
