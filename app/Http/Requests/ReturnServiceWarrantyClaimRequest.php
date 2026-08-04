<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReturnServiceWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $claim = $this->route('serviceWarrantyClaim');

        return $user?->can('return-service-warranty-claims')
            && $order
            && $claim
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $claim->organization_id
                === (int) $order->organization_id
            && in_array(
                (int) $order->id,
                [
                    (int) $claim->original_service_order_id,
                    (int) $claim->corrective_service_order_id,
                ],
                true
            );
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'recipient_business_party_id' => filled(
                $this->input('recipient_business_party_id')
            ) ? (int) $this->input('recipient_business_party_id') : null,
            'recipient_name' => Str::squish(
                (string) $this->input('recipient_name')
            ),
            'recipient_document' => $this->optional(
                (string) $this->input('recipient_document')
            ),
            'condition_notes' => $this->multiline(
                (string) $this->input('condition_notes')
            ),
            'accessories_snapshot' => $this->multiline(
                (string) $this->input('accessories_snapshot')
            ),
            'notes' => $this->optionalMultiline(
                (string) $this->input('notes')
            ),
            'returned_at' => $this->optional(
                (string) $this->input('returned_at')
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
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_document' => ['nullable', 'string', 'max:255'],
            'condition_notes' => ['required', 'string', 'max:5000'],
            'accessories_snapshot' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'returned_at' => ['nullable', 'date'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:warranty-return:[0-9a-f-]{36}$/',
            ],
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function optionalMultiline(string $value): ?string
    {
        $value = $this->multiline($value);

        return $value === '' ? null : $value;
    }

    private function multiline(string $value): string
    {
        return trim(preg_replace('/\R/u', "\n", $value) ?? $value);
    }
}
