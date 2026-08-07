<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('draft-purchase-orders') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect((array) $this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->filter(fn (array $line): bool =>
                filled($line['catalog_product_id'] ?? null)
                || filled($line['supplier_offer_id'] ?? null)
                || filled($line['quantity'] ?? null)
                || filled($line['unit_cost'] ?? null)
                || filled($line['supplier_code'] ?? null)
                || filled($line['description'] ?? null))
            ->map(fn (array $line): array => [
                'catalog_product_id' => filled(
                    $line['catalog_product_id'] ?? null
                ) ? (int) $line['catalog_product_id'] : null,
                'supplier_offer_id' => filled(
                    $line['supplier_offer_id'] ?? null
                ) ? (int) $line['supplier_offer_id'] : null,
                'quantity' => $this->quantity(
                    (string) ($line['quantity'] ?? '')
                ),
                'unit_cost' => $this->money(
                    (string) ($line['unit_cost'] ?? '')
                ),
                'supplier_code' => $this->optional(
                    (string) ($line['supplier_code'] ?? '')
                ),
                'description' => $this->optional(
                    (string) ($line['description'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $this->merge([
            'supplier_id' => filled($this->input('supplier_id'))
                ? (int) $this->input('supplier_id')
                : null,
            'currency_code' => strtoupper(trim(
                (string) $this->input('currency_code', 'ARS')
            )),
            'expected_logistics_cost' => $this->money(
                (string) $this->input('expected_logistics_cost', '0')
            ),
            'notes' => $this->optional(
                (string) $this->input('notes')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id(
            $this->user()
        );
        $money = [
            'required',
            'string',
            'max:18',
            'regex:/^\d{1,14}(?:\.\d{1,2})?$/',
        ];

        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'expected_logistics_cost' => $money,
            'notes' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.catalog_product_id' => [
                'required',
                'integer',
                Rule::exists('catalog_products', 'id')
                    ->where('active', true),
            ],
            'lines.*.supplier_offer_id' => [
                'nullable',
                'integer',
                Rule::exists('supplier_offers', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'lines.*.unit_cost' => $money,
            'lines.*.supplier_code' => [
                'nullable',
                'string',
                'max:255',
            ],
            'lines.*.description' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'El proveedor no pertenece a la organización activa o está inactivo.',
            'lines.min' => 'La orden requiere al menos una línea.',
            'lines.*.catalog_product_id.exists' => 'El producto no existe o está inactivo.',
            'lines.*.supplier_offer_id.exists' => 'La oferta no pertenece a la organización activa o está inactiva.',
            'lines.*.quantity.regex' => 'La cantidad debe ser positiva y admitir hasta seis decimales.',
            'lines.*.unit_cost.regex' => 'El costo unitario no puede ser negativo y admite hasta dos decimales.',
            'expected_logistics_cost.regex' => 'El costo logístico no puede ser negativo y admite hasta dos decimales.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function money(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }

    private function quantity(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
