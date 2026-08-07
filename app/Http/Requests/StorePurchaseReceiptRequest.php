<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('receive-purchases') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect((array) $this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->filter(fn (array $line): bool =>
                filled($line['purchase_order_line_id'] ?? null)
                || filled($line['quantity'] ?? null)
                || filled($line['inventory_location_id'] ?? null)
                || filled($line['actual_unit_cost'] ?? null))
            ->map(fn (array $line): array => [
                'purchase_order_line_id' => filled(
                    $line['purchase_order_line_id'] ?? null
                ) ? (int) $line['purchase_order_line_id'] : null,
                'quantity' => $this->decimal(
                    (string) ($line['quantity'] ?? '')
                ),
                'inventory_location_id' => filled(
                    $line['inventory_location_id'] ?? null
                ) ? (int) $line['inventory_location_id'] : null,
                'condition' => strtolower(trim(
                    (string) ($line['condition'] ?? '')
                )),
                'actual_unit_cost' => $this->decimal(
                    (string) ($line['actual_unit_cost'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $this->merge([
            'received_at' => trim(
                (string) $this->input('received_at')
            ),
            'document_reference' => $this->optional(
                (string) $this->input('document_reference')
            ),
            'logistics_cost' => $this->decimal(
                (string) $this->input('logistics_cost', '0')
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
            'received_at' => ['required', 'date'],
            'document_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'logistics_cost' => $money,
            'notes' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^purchase-ui:receipt:[0-9a-f-]{36}$/',
            ],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'integer',
                Rule::exists('purchase_order_lines', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'lines.*.inventory_location_id' => [
                'required',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'lines.*.condition' => [
                'required',
                Rule::enum(InventoryCondition::class),
            ],
            'lines.*.actual_unit_cost' => $money,
        ];
    }

    public function messages(): array
    {
        return [
            'lines.min' => 'Seleccione al menos una línea para recibir.',
            'lines.*.purchase_order_line_id.exists' => 'La línea no pertenece a la organización activa.',
            'lines.*.inventory_location_id.exists' => 'La ubicación no pertenece a la organización activa o está inactiva.',
            'lines.*.quantity.regex' => 'La cantidad recibida debe ser positiva y admitir hasta seis decimales.',
            'lines.*.actual_unit_cost.regex' => 'El costo unitario real no puede ser negativo y admite hasta dos decimales.',
            'logistics_cost.regex' => 'El costo logístico real no puede ser negativo y admite hasta dos decimales.',
            'idempotency_key.regex' => 'La clave de seguridad de recepción no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function decimal(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
