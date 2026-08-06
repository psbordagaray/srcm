<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServicePartSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServicePartConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $requirement = $this->route('servicePartRequirement');

        return $user?->can('consume-service-parts')
            && $order
            && $requirement
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $requirement->organization_id
                === (int) $order->organization_id
            && (int) $requirement->service_order_id
                === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantity' => str_replace(
                ',',
                '.',
                trim((string) $this->input('quantity'))
            ),
            'source_location_id' => filled($this->input('source_location_id'))
                    ? (int) $this->input('source_location_id')
                    : null,
            'service_part_purchase_line_id' => filled($this->input('service_part_purchase_line_id'))
                    ? (int) $this->input(
                        'service_part_purchase_line_id'
                    )
                    : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id($this->user());

        return [
            'quantity' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'source_location_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'service_part_purchase_line_id' => [
                'nullable',
                'integer',
                Rule::exists('service_part_purchase_lines', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:part-consumption:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $requirement = $this->route('servicePartRequirement');

            if (! $requirement) {
                return;
            }

            if ($requirement->source === ServicePartSource::Stock) {
                if ($this->input('source_location_id') === null) {
                    $validator->errors()->add(
                        'source_location_id',
                        'Seleccioná la ubicación que entrega el repuesto.'
                    );
                }

                if (
                    $this->input(
                        'service_part_purchase_line_id'
                    ) !== null
                ) {
                    $validator->errors()->add(
                        'service_part_purchase_line_id',
                        'Un consumo desde stock no utiliza una compra afectada.'
                    );
                }

                return;
            }

            if (
                $this->input(
                    'service_part_purchase_line_id'
                ) === null
            ) {
                $validator->errors()->add(
                    'service_part_purchase_line_id',
                    'Seleccioná la línea de compra que entrega el repuesto.'
                );
            }

            if ($this->input('source_location_id') !== null) {
                $validator->errors()->add(
                    'source_location_id',
                    'Una compra afectada no puede descontarse también del stock.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'quantity.regex' => 'La cantidad admite hasta seis decimales.',
            'source_location_id.exists' => 'La ubicación no pertenece a la organización activa.',
            'service_part_purchase_line_id.exists' => 'La línea de compra no pertenece a la organización activa.',
            'idempotency_key.regex' => 'La clave de seguridad del consumo no es válida.',
        ];
    }
}
