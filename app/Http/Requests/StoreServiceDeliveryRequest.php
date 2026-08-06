<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('deliver-service-orders')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'recipient_business_party_id' => filled($this->input('recipient_business_party_id'))
                    ? (int) $this->input(
                        'recipient_business_party_id'
                    )
                    : null,
            'recipient_name' => trim(
                (string) $this->input('recipient_name')
            ),
            'recipient_document' => $this->optional(
                (string) $this->input('recipient_document')
            ),
            'condition_notes' => trim(
                (string) $this->input('condition_notes')
            ),
            'accessories_snapshot' => trim(
                (string) $this->input('accessories_snapshot')
            ),
            'customer_conformity' => filter_var(
                $this->input('customer_conformity', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'notes' => $this->optional(
                (string) $this->input('notes')
            ),
            'delivered_at' => $this->optional(
                (string) $this->input('delivered_at')
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
            'recipient_business_party_id' => [
                'nullable',
                'integer',
                Rule::exists('business_parties', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'recipient_name' => [
                'required',
                'string',
                'max:255',
            ],
            'recipient_document' => [
                'nullable',
                'string',
                'max:255',
            ],
            'condition_notes' => [
                'required',
                'string',
                'max:5000',
            ],
            'accessories_snapshot' => [
                'required',
                'string',
                'max:5000',
            ],
            'customer_conformity' => [
                'required',
                'boolean',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'delivered_at' => ['nullable', 'date'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:delivery:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                ! $this->boolean('customer_conformity')
                && $this->input('notes') === null
            ) {
                $validator->errors()->add(
                    'notes',
                    'La entrega sin conformidad requiere observaciones.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'recipient_business_party_id.exists' => 'El receptor seleccionado no pertenece a la organización activa.',
            'recipient_name.required' => 'Indicá quién recibe el equipo.',
            'condition_notes.required' => 'Describí la condición en la entrega.',
            'accessories_snapshot.required' => 'Confirmá los accesorios entregados.',
            'delivered_at.date' => 'La fecha de entrega no es válida.',
            'idempotency_key.regex' => 'La clave de seguridad de la entrega no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
