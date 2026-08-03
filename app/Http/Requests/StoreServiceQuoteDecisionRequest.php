<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceQuoteDecisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceQuoteDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $quote = $this->route('serviceQuote');

        return $user?->can('record-service-quote-decisions')
            && $order
            && $quote
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $quote->organization_id
                === (int) $order->organization_id
            && (int) $quote->service_order_id === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'decision' => Str::lower(trim(
                (string) $this->input('decision')
            )),
            'service_quote_option_id' => filled(
                $this->input('service_quote_option_id')
            ) ? (int) $this->input('service_quote_option_id') : null,
            'customer_name' => Str::squish(
                (string) $this->input('customer_name')
            ),
            'customer_reference' => $this->optional(
                (string) $this->input('customer_reference')
            ),
            'channel' => Str::squish((string) $this->input('channel')),
            'reason' => $this->optional((string) $this->input('reason')),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $quoteId = (int) $this->route('serviceQuote')?->id;

        return [
            'decision' => [
                'required',
                Rule::enum(ServiceQuoteDecisionType::class),
            ],
            'service_quote_option_id' => [
                'nullable',
                'integer',
                Rule::exists('service_quote_options', 'id')
                    ->where('service_quote_id', $quoteId),
            ],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'channel' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:decision:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $approved = $this->input('decision')
                === ServiceQuoteDecisionType::Approved->value;
            $rejected = $this->input('decision')
                === ServiceQuoteDecisionType::Rejected->value;

            if ($approved && ! $this->input('service_quote_option_id')) {
                $validator->errors()->add(
                    'service_quote_option_id',
                    'Seleccioná la alternativa aprobada.'
                );
            }

            if ($rejected && $this->input('service_quote_option_id')) {
                $validator->errors()->add(
                    'service_quote_option_id',
                    'Un rechazo no debe seleccionar una alternativa.'
                );
            }

            if ($rejected && ! $this->input('reason')) {
                $validator->errors()->add(
                    'reason',
                    'Registrá el motivo informado por el cliente.'
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
