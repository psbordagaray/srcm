<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceEvidenceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('upload-service-evidence')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $target = trim((string) $this->input('target'));
        [$context, $reference] = array_pad(
            explode(':', $target, 2),
            2,
            null
        );

        $this->merge([
            'target' => $target,
            'context' => $context,
            'reference_id' => is_string($reference)
                && ctype_digit($reference)
                && (int) $reference > 0
                    ? (int) $reference
                    : null,
            'description' => $this->optionalMultiline(
                $this->input('description')
            ),
            'captured_at' => $this->optional(
                $this->input('captured_at')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $maximumKilobytes = max(
            1,
            (int) ceil(
                ((int) config('service_evidence.max_bytes')) / 1024
            )
        );

        return [
            'target' => [
                'required',
                'string',
                'max:100',
                'regex:/^(order|(?:intake|diagnostic|work_item|part_requirement|custody_event|quality_inspection|delivery|cancellation_request|cancellation_resolution|cancellation_return|warranty_claim|warranty_resolution|warranty_return):[1-9][0-9]*)$/',
            ],
            'context' => [
                'required',
                Rule::enum(ServiceEvidenceContext::class),
            ],
            'reference_id' => ['nullable', 'integer', 'min:1'],
            'evidence_file' => [
                'required',
                'file',
                'max:'.$maximumKilobytes,
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:evidence:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $context = ServiceEvidenceContext::tryFrom(
                    (string) $this->input('context')
                );
                $referenceId = $this->input('reference_id');

                if (! $context) {
                    return;
                }

                if (
                    $context->requiresReference()
                    && ! is_int($referenceId)
                ) {
                    $validator->errors()->add(
                        'target',
                        'El contexto seleccionado requiere una referencia.'
                    );
                }

                if (
                    ! $context->requiresReference()
                    && $referenceId !== null
                ) {
                    $validator->errors()->add(
                        'target',
                        'El expediente general no admite referencia.'
                    );
                }
            },
        ];
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function optionalMultiline(mixed $value): ?string
    {
        $value = preg_replace(
            '/\R/u',
            "\n",
            trim((string) $value)
        );

        return ! is_string($value) || $value === ''
            ? null
            : $value;
    }
}
