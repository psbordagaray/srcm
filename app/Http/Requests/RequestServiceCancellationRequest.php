<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceCancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RequestServiceCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('request-service-cancellation')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => Str::lower(trim((string) $this->input('reason'))),
            'requester_business_party_id' => filled(
                $this->input('requester_business_party_id')
            ) ? (int) $this->input('requester_business_party_id') : null,
            'requester_name' => Str::squish(
                (string) $this->input('requester_name')
            ),
            'customer_reference' => $this->optional(
                (string) $this->input('customer_reference')
            ),
            'channel' => Str::squish((string) $this->input('channel')),
            'details' => $this->optional((string) $this->input('details')),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id($this->user());

        return [
            'reason' => ['required', Rule::enum(ServiceCancellationReason::class)],
            'requester_business_party_id' => [
                'nullable',
                'integer',
                Rule::exists('business_parties', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'requester_name' => ['required', 'string', 'max:255'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'channel' => ['required', 'string', 'max:50'],
            'details' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:cancellation-request:[0-9a-f-]{36}$/',
            ],
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
