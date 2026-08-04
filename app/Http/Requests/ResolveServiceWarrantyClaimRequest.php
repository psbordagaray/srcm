<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyTemporalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveServiceWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $claim = $this->route('serviceWarrantyClaim');

        return $user?->can('resolve-service-warranty-claims')
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
            'outcome' => Str::lower(trim((string) $this->input('outcome'))),
            'technical_basis' => $this->multiline(
                (string) $this->input('technical_basis')
            ),
            'covered_scope' => $this->optionalMultiline(
                (string) $this->input('covered_scope')
            ),
            'excluded_scope' => $this->optionalMultiline(
                (string) $this->input('excluded_scope')
            ),
            'exception_reason' => $this->optionalMultiline(
                (string) $this->input('exception_reason')
            ),
            'notes' => $this->optionalMultiline(
                (string) $this->input('notes')
            ),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'outcome' => [
                'required',
                Rule::enum(ServiceWarrantyClaimOutcome::class),
            ],
            'technical_basis' => ['required', 'string', 'max:5000'],
            'covered_scope' => ['nullable', 'string', 'max:5000'],
            'excluded_scope' => ['nullable', 'string', 'max:5000'],
            'exception_reason' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:warranty-resolution:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $outcome = ServiceWarrantyClaimOutcome::tryFrom(
                (string) $this->input('outcome')
            );
            $covered = filled($this->input('covered_scope'));
            $excluded = filled($this->input('excluded_scope'));
            $exception = filled($this->input('exception_reason'));
            $claim = $this->route('serviceWarrantyClaim');
            $expired = $claim?->warranty_status_at_claim
                === ServiceWarrantyTemporalStatus::Expired;

            if ($outcome === ServiceWarrantyClaimOutcome::Accepted) {
                if (! $covered) {
                    $validator->errors()->add(
                        'covered_scope',
                        'La aceptación total requiere describir el alcance cubierto.'
                    );
                }

                if ($excluded) {
                    $validator->errors()->add(
                        'excluded_scope',
                        'La aceptación total no admite alcance excluido.'
                    );
                }
            }

            if ($outcome === ServiceWarrantyClaimOutcome::PartiallyAccepted) {
                if (! $covered) {
                    $validator->errors()->add(
                        'covered_scope',
                        'La aceptación parcial requiere alcance cubierto.'
                    );
                }

                if (! $excluded) {
                    $validator->errors()->add(
                        'excluded_scope',
                        'La aceptación parcial requiere alcance excluido.'
                    );
                }
            }

            if ($outcome === ServiceWarrantyClaimOutcome::Rejected) {
                if ($covered) {
                    $validator->errors()->add(
                        'covered_scope',
                        'El rechazo no admite alcance cubierto.'
                    );
                }

                if (! $excluded) {
                    $validator->errors()->add(
                        'excluded_scope',
                        'El rechazo requiere describir el alcance excluido.'
                    );
                }
            }

            $authorizesWork = $outcome?->authorizesCorrectiveWork() ?? false;

            if ($expired && $authorizesWork && ! $exception) {
                $validator->errors()->add(
                    'exception_reason',
                    'Aceptar fuera de término requiere un motivo administrativo.'
                );
            }

            if ((! $expired || ! $authorizesWork) && $exception) {
                $validator->errors()->add(
                    'exception_reason',
                    'El motivo de excepción sólo corresponde a una aceptación fuera de término.'
                );
            }
        }];
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
