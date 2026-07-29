<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicalModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    public function rules(): array
    {
        $technicalModel = $this->route('technical_model');

        return [
            'brand_id' => [
                'required',
                'integer',
                Rule::exists('brands', 'id')->where('active', true),
            ],

            'product_category_id' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')->where('active', true),
            ],

            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('technical_models')
                    ->ignore($technicalModel)
                    ->where(
                        fn ($query) => $query
                            ->where('brand_id', $this->integer('brand_id'))
                            ->where('product_category_id', $this->integer('product_category_id'))
                    ),
            ],

            'name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_id.required' => 'La marca es obligatoria.',
            'brand_id.exists' => 'La marca seleccionada no existe o está inactiva.',

            'product_category_id.required' => 'La categoría es obligatoria.',
            'product_category_id.exists' => 'La categoría seleccionada no existe o está inactiva.',

            'code.required' => 'El código del modelo es obligatorio.',
            'code.max' => 'El código no puede superar los 100 caracteres.',
            'code.unique' => 'Ya existe un modelo con esa marca, categoría y código.',

            'name.max' => 'El nombre no puede superar los 255 caracteres.',

            'active.required' => 'El estado es obligatorio.',
            'active.boolean' => 'El estado seleccionado no es válido.',
        ];
    }
}
