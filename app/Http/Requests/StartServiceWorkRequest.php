<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class StartServiceWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $work = $this->route('serviceWorkItem');

        return $user?->can('execute-service-work')
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
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:work-start:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
