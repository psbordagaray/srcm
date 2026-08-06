<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServicePartPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('record-service-part-purchases')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $lines = collect((array) $this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'service_part_requirement_id' => filled($line['service_part_requirement_id'] ?? null)
                        ? (int) $line['service_part_requirement_id']
                        : null,
                'quantity' => str_replace(
                    ',',
                    '.',
                    trim((string) ($line['quantity'] ?? ''))
                ),
                'unit_cost' => str_replace(
                    ',',
                    '.',
                    trim((string) ($line['unit_cost'] ?? ''))
                ),
            ])
            ->filter(fn (array $line): bool => $line['quantity'] !== ''
                || $line['unit_cost'] !== ''
            )
            ->values()
            ->all();

        $this->merge([
            'supplier_id' => filled($this->input('supplier_id'))
                ? (int) $this->input('supplier_id')
                : null,
            'currency_code' => Str::upper(trim(
                (string) $this->input('currency_code', 'ARS')
            )),
            'purchased_at' => trim(
                (string) $this->input('purchased_at')
            ),
            'logistics_cost' => str_replace(
                ',',
                '.',
                trim((string) $this->input('logistics_cost', '0'))
            ),
            'document_reference' => $this->optional(
                (string) $this->input('document_reference')
            ),
            'notes' => $this->optional(
                (string) $this->input('notes')
            ),
            'lines' => $lines,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id($this->user());

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
                'regex:/^[A-Z]{3}$/',
            ],
            'purchased_at' => ['required', 'date'],
            'logistics_cost' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'document_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.service_part_requirement_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('service_part_requirements', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'lines.*.quantity' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'lines.*.unit_cost' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:part-purchase:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Seleccioná el proveedor.',
            'supplier_id.exists' => 'El proveedor no existe, está inactivo o es ajeno.',
            'currency_code.regex' => 'La moneda debe expresarse con tres letras.',
            'purchased_at.required' => 'Indicá cuándo se recibió o confirmó la compra.',
            'logistics_cost.regex' => 'El costo logístico admite hasta dos decimales.',
            'lines.min' => 'Imputá al menos un repuesto a la compra.',
            'lines.*.quantity.regex' => 'La cantidad admite hasta seis decimales.',
            'lines.*.unit_cost.regex' => 'El costo unitario admite hasta dos decimales.',
            'idempotency_key.regex' => 'La clave de seguridad de la compra no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
