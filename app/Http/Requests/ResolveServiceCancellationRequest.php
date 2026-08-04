<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceCancellationFinancialOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveServiceCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $cancellation = $this->route('serviceCancellationRequest');

        return $user?->can('resolve-service-cancellation')
            && $order
            && $cancellation
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $cancellation->organization_id
                === (int) $order->organization_id
            && (int) $cancellation->service_order_id === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $charge = trim((string) $this->input('customer_charge'));

        $this->merge([
            'financial_outcome' => Str::lower(trim(
                (string) $this->input('financial_outcome')
            )),
            'currency_code' => Str::upper(trim(
                (string) $this->input('currency_code', 'ARS')
            )),
            'customer_charge' => $charge === ''
                ? null
                : str_replace(',', '.', $charge),
            'customer_acceptance_reference' => $this->optional(
                (string) $this->input('customer_acceptance_reference')
            ),
            'work_disposition' => Str::squish(
                (string) $this->input('work_disposition')
            ),
            'parts_disposition' => Str::squish(
                (string) $this->input('parts_disposition')
            ),
            'financial_disposition' => Str::squish(
                (string) $this->input('financial_disposition')
            ),
            'return_condition_notes' => Str::squish(
                (string) $this->input('return_condition_notes')
            ),
            'accessories_snapshot' => Str::squish(
                (string) $this->input('accessories_snapshot')
            ),
            'notes' => $this->optional((string) $this->input('notes')),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'financial_outcome' => [
                'required',
                Rule::enum(ServiceCancellationFinancialOutcome::class),
            ],
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'customer_charge' => [
                'nullable',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'customer_acceptance_reference' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'work_disposition' => ['required', 'string', 'max:5000'],
            'parts_disposition' => ['required', 'string', 'max:5000'],
            'financial_disposition' => ['required', 'string', 'max:5000'],
            'return_condition_notes' => ['required', 'string', 'max:5000'],
            'accessories_snapshot' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:cancellation-resolution:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $isCharge = $this->input('financial_outcome')
                === ServiceCancellationFinancialOutcome::CustomerCharge->value;
            $charge = $this->input('customer_charge');
            $positiveCharge = is_string($charge)
                && preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $charge) === 1
                && (float) $charge > 0;

            if ($isCharge && ! $positiveCharge) {
                $validator->errors()->add(
                    'customer_charge',
                    'Informá un cargo mayor que cero.'
                );
            }

            if ($isCharge && ! $this->input('customer_acceptance_reference')) {
                $validator->errors()->add(
                    'customer_acceptance_reference',
                    'Registrá la aceptación verificable del cliente.'
                );
            }

            if (! $isCharge && $positiveCharge) {
                $validator->errors()->add(
                    'customer_charge',
                    'Sólo corresponde informar un cargo cuando fue acordado con el cliente.'
                );
            }
        }];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
