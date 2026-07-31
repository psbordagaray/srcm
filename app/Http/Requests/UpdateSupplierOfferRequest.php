<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\SupplierOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateSupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-commerce') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $cost = $this->filled('cost_amount')
            ? str_replace(',', '.', trim((string) $this->input('cost_amount')))
            : null;

        $url = $this->filled('source_url')
            ? trim((string) $this->input('source_url'))
            : null;

        if ($url !== null && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $this->merge([
            'supplier_id' => $this->integer('supplier_id'),
            'catalog_product_id' => $this->integer('catalog_product_id'),
            'supplier_code' => $this->filled('supplier_code')
                ? Str::of((string) $this->input('supplier_code'))
                    ->squish()
                    ->upper()
                    ->toString()
                : null,
            'published_description' => $this->filled('published_description')
                ? Str::of((string) $this->input('published_description'))
                    ->trim()
                    ->toString()
                : null,
            'cost_amount' => $cost,
            'currency' => $cost !== null
                ? Str::of((string) $this->input('currency'))
                    ->trim()
                    ->upper()
                    ->toString()
                : null,
            'availability_status' => Str::of(
                (string) $this->input(
                    'availability_status',
                    SupplierOffer::AVAILABILITY_UNKNOWN
                )
            )->trim()->lower()->toString(),
            'source_url' => $url,
            'commercial_terms' => $this->filled('commercial_terms')
                ? Str::of((string) $this->input('commercial_terms'))
                    ->trim()
                    ->toString()
                : null,
            'active' => $this->boolean('active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')
                    ->where(
                        fn ($query) => $query
                            ->where('active', true)
                            ->where(
                                'organization_id',
                                app(CurrentOrganization::class)->id()
                            )
                    ),
            ],
            'catalog_product_id' => [
                'required',
                'integer',
                Rule::exists('catalog_products', 'id')->where('active', true),
            ],
            'supplier_code' => ['nullable', 'string', 'max:120'],
            'published_description' => ['nullable', 'string', 'max:2000'],
            'cost_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.99',
                'decimal:0,2',
            ],
            'currency' => [
                'nullable',
                'required_with:cost_amount',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'availability_status' => [
                'required',
                Rule::in(array_keys(SupplierOffer::availabilityOptions())),
            ],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'checked_at' => ['required', 'date', 'before_or_equal:today'],
            'commercial_terms' => ['nullable', 'string', 'max:5000'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'El proveedor es obligatorio.',
            'supplier_id.exists' => 'El proveedor no existe o está inactivo.',
            'catalog_product_id.required' => 'El producto es obligatorio.',
            'catalog_product_id.exists' => 'El producto no existe o está inactivo.',
            'cost_amount.numeric' => 'El costo debe ser numérico.',
            'cost_amount.min' => 'El costo no puede ser negativo.',
            'cost_amount.decimal' => 'El costo admite hasta dos decimales.',
            'currency.required_with' => 'Indicá la moneda del costo.',
            'currency.size' => 'La moneda debe usar un código de tres letras.',
            'currency.regex' => 'La moneda debe contener tres letras mayúsculas.',
            'availability_status.in' => 'La disponibilidad no es válida.',
            'source_url.url' => 'La URL debe ser válida.',
            'checked_at.required' => 'La fecha de verificación es obligatoria.',
            'checked_at.before_or_equal' => 'La fecha no puede estar en el futuro.',
            'active.required' => 'El estado es obligatorio.',
        ];
    }
}
