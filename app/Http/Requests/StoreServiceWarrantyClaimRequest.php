<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServiceWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $warranty = $this->route('serviceWarrantyGrant');
        $warranty?->loadMissing('delivery');

        return $user?->can('register-service-warranty-claims')
            && $order
            && $warranty
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $warranty->organization_id
                === (int) $order->organization_id
            && (int) $warranty->delivery?->service_order_id
                === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'intake_location_id' => (int) $this->input('intake_location_id'),
            'claimant_business_party_id' => filled(
                $this->input('claimant_business_party_id')
            ) ? (int) $this->input('claimant_business_party_id') : null,
            'claimant_name' => Str::squish(
                (string) $this->input('claimant_name')
            ),
            'reported_issue' => $this->multiline(
                (string) $this->input('reported_issue')
            ),
            'reentry_condition_notes' => $this->multiline(
                (string) $this->input('reentry_condition_notes')
            ),
            'accessories_snapshot' => $this->multiline(
                (string) $this->input('accessories_snapshot')
            ),
            'channel' => Str::squish((string) $this->input('channel')),
            'claimed_at' => trim((string) $this->input('claimed_at')),
            'customer_reference' => $this->optional(
                (string) $this->input('customer_reference')
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
            'intake_location_id' => [
                'required',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'claimant_business_party_id' => [
                'nullable',
                'integer',
                Rule::exists('business_parties', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'claimant_name' => ['required', 'string', 'max:255'],
            'reported_issue' => ['required', 'string', 'max:5000'],
            'reentry_condition_notes' => ['required', 'string', 'max:5000'],
            'accessories_snapshot' => ['required', 'string', 'max:5000'],
            'channel' => ['required', 'string', 'max:100'],
            'claimed_at' => ['required', 'date'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:warranty-claim:[0-9a-f-]{36}$/',
            ],
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function multiline(string $value): string
    {
        return trim(preg_replace('/\R/u', "\n", $value) ?? $value);
    }
}
