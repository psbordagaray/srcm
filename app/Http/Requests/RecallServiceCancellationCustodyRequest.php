<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RecallServiceCancellationCustodyRequest extends FormRequest
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
            && (int) $work->organization_id === (int) $order->organization_id
            && (int) $work->service_order_id === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'condition_notes' => Str::squish(
                (string) $this->input('condition_notes')
            ),
            'accessories_snapshot' => Str::squish(
                (string) $this->input('accessories_snapshot')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'condition_notes' => ['required', 'string', 'max:5000'],
            'accessories_snapshot' => ['required', 'string', 'max:5000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:cancellation-recall:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
