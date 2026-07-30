<?php

namespace App\Http\Requests;

use App\Models\CatalogProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCatalogProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => Str::of((string) $this->input('sku'))
                ->squish()
                ->upper()
                ->toString(),
            'name' => Str::of((string) $this->input('name'))
                ->squish()
                ->toString(),
            'description' => $this->filled('description')
                ? Str::of((string) $this->input('description'))
                    ->trim()
                    ->toString()
                : null,
            'brand_id' => $this->filled('brand_id')
                ? $this->integer('brand_id')
                : null,
            'manufacturer_id' => $this->filled('manufacturer_id')
                ? $this->integer('manufacturer_id')
                : null,
            'active' => $this->boolean('active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'product_category_id' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')
                    ->where('active', true),
            ],
            'brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')
                    ->where('active', true),
            ],
            'manufacturer_id' => [
                'nullable',
                'integer',
                Rule::exists('manufacturers', 'id')
                    ->where('active', true),
            ],
            'sku' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $normalizedSku = CatalogProduct::normalizeIdentity(
                (string) $this->input('sku')
            );

            if (
                CatalogProduct::query()
                    ->where('normalized_sku', $normalizedSku)
                    ->exists()
            ) {
                $validator->errors()->add(
                    'sku',
                    'Ya existe un producto con un SKU equivalente.'
                );

                return;
            }

            $brandId = $this->input('brand_id');

            $probableDuplicate = CatalogProduct::query()
                ->where(
                    'product_category_id',
                    $this->integer('product_category_id')
                )
                ->where(
                    'normalized_name',
                    CatalogProduct::normalizeIdentity(
                        (string) $this->input('name')
                    )
                )
                ->when(
                    $brandId !== null,
                    fn ($query) => $query->where('brand_id', $brandId),
                    fn ($query) => $query->whereNull('brand_id')
                )
                ->exists();

            if ($probableDuplicate) {
                $validator->errors()->add(
                    'name',
                    'Ya existe un producto con este nombre, marca y categoría. Vinculá códigos adicionales desde su ficha.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'product_category_id.required' => 'La categoría es obligatoria.',
            'product_category_id.exists' => 'La categoría no existe o está inactiva.',
            'brand_id.exists' => 'La marca no existe o está inactiva.',
            'manufacturer_id.exists' => 'El fabricante no existe o está inactivo.',
            'sku.required' => 'El SKU o código principal es obligatorio.',
            'name.required' => 'El nombre canónico es obligatorio.',
            'active.required' => 'El estado es obligatorio.',
        ];
    }
}
