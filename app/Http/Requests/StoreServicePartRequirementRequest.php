<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Enums\ServicePartSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServicePartRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $work = $this->route('serviceWorkItem');

        return $user?->can('plan-service-parts')
            && $order
            && $work
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $work->organization_id
                === (int) $order->organization_id
            && (int) $work->service_order_id === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'service_quote_line_id' => filled($this->input('service_quote_line_id'))
                    ? (int) $this->input('service_quote_line_id')
                    : null,
            'catalog_product_id' => filled($this->input('catalog_product_id'))
                    ? (int) $this->input('catalog_product_id')
                    : null,
            'condition' => strtolower(trim(
                (string) $this->input('condition')
            )),
            'source' => strtolower(trim(
                (string) $this->input('source')
            )),
            'required_quantity' => $this->optionalQuantity(
                (string) $this->input('required_quantity')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id($this->user());

        return [
            'service_quote_line_id' => [
                'nullable',
                'integer',
                Rule::exists('service_quote_lines', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'catalog_product_id' => [
                'required',
                'integer',
                Rule::exists('catalog_products', 'id')
                    ->where('active', true),
            ],
            'condition' => [
                'required',
                Rule::enum(InventoryCondition::class),
            ],
            'source' => [
                'required',
                Rule::enum(ServicePartSource::class),
            ],
            'required_quantity' => [
                'nullable',
                'regex:/^\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:part-requirement:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $work = $this->route('serviceWorkItem');

            if (! $work) {
                return;
            }

            $warrantyMode =
                $work->service_warranty_claim_resolution_id !== null;

            if (
                $warrantyMode
                && $this->input('service_quote_line_id') !== null
            ) {
                $validator->errors()->add(
                    'service_quote_line_id',
                    'Un repuesto de garantía no puede imputarse a un presupuesto.'
                );
            }

            if (
                $warrantyMode
                && $this->input('required_quantity') === null
            ) {
                $validator->errors()->add(
                    'required_quantity',
                    'Indicá la cantidad cubierta por la garantía.'
                );
            }

            if (
                ! $warrantyMode
                && $this->input('service_quote_line_id') === null
            ) {
                $validator->errors()->add(
                    'service_quote_line_id',
                    'Seleccioná una línea de repuesto del presupuesto aprobado.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'service_quote_line_id.exists' => 'La línea no pertenece a la organización activa.',
            'catalog_product_id.required' => 'Seleccioná el producto que identifica al repuesto.',
            'catalog_product_id.exists' => 'El producto no existe o está inactivo.',
            'condition.enum' => 'La condición física del repuesto no es válida.',
            'source.enum' => 'El origen del repuesto no es válido.',
            'required_quantity.regex' => 'La cantidad admite hasta seis decimales.',
            'idempotency_key.regex' => 'La clave de seguridad del repuesto no es válida.',
        ];
    }

    private function optionalQuantity(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));

        return $value === '' ? null : $value;
    }
}
