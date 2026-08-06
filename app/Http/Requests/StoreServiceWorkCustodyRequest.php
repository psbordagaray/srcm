<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceWorkCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $work = $this->route('serviceWorkItem');

        return $user?->can('transfer-service-custody')
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
            'condition_notes' => trim(
                (string) $this->input('condition_notes')
            ),
            'accessories_snapshot' => trim(
                (string) $this->input('accessories_snapshot')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $direction = $this->routeIs(
            'service-orders.work-items.return.store'
        ) ? 'return' : 'dispatch';

        return [
            'condition_notes' => [
                'required',
                'string',
                'min:3',
                'max:5000',
            ],
            'accessories_snapshot' => [
                'required',
                'string',
                'min:2',
                'max:5000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:work-'.$direction.':[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'condition_notes.required' => 'Registrá la condición del equipo al transferirlo.',
            'accessories_snapshot.required' => 'Detallá los accesorios que acompañan al equipo.',
            'idempotency_key.regex' => 'La clave de seguridad de custodia no es válida.',
        ];
    }
}
