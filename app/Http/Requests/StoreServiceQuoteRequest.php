<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceQuoteLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('issue-service-quotes')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $options = collect((array) $this->input('options', []))
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(function (array $option): array {
                $lines = collect((array) ($option['lines'] ?? []))
                    ->filter(fn (mixed $line): bool => is_array($line))
                    ->map(fn (array $line): array => [
                        'type' => Str::lower(trim(
                            (string) ($line['type'] ?? '')
                        )),
                        'description' => Str::squish(
                            (string) ($line['description'] ?? '')
                        ),
                        'quantity' => str_replace(
                            ',',
                            '.',
                            trim((string) ($line['quantity'] ?? ''))
                        ),
                        'unit_price' => str_replace(
                            ',',
                            '.',
                            trim((string) ($line['unit_price'] ?? ''))
                        ),
                    ])
                    ->filter(fn (array $line): bool =>
                        $line['description'] !== ''
                        || $line['unit_price'] !== ''
                    )->values()->all();

                return [
                    'label' => Str::squish((string) ($option['label'] ?? '')),
                    'description' => $this->optional(
                        (string) ($option['description'] ?? '')
                    ),
                    'recommended' => filter_var(
                        $option['recommended'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'lines' => $lines,
                ];
            })
            ->filter(fn (array $option): bool =>
                $option['label'] !== '' || $option['lines'] !== []
            )->values()->all();

        $this->merge([
            'currency_code' => Str::upper(trim(
                (string) $this->input('currency_code', 'ARS')
            )),
            'valid_until' => $this->optional(
                (string) $this->input('valid_until')
            ),
            'terms' => $this->optional((string) $this->input('terms')),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'options' => $options,
        ]);
    }

    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'options' => ['required', 'array', 'min:1', 'max:5'],
            'options.*.label' => ['required', 'string', 'max:150'],
            'options.*.description' => ['nullable', 'string', 'max:2000'],
            'options.*.recommended' => ['required', 'boolean'],
            'options.*.lines' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.lines.*.type' => [
                'required',
                Rule::enum(ServiceQuoteLineType::class),
            ],
            'options.*.lines.*.description' => [
                'required',
                'string',
                'max:500',
            ],
            'options.*.lines.*.quantity' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'options.*.lines.*.unit_price' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:quote:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $recommended = collect((array) $this->input('options', []))
                ->filter(fn (array $option): bool =>
                    (bool) ($option['recommended'] ?? false)
                )->count();

            if ($recommended > 1) {
                $validator->errors()->add(
                    'options',
                    'Sólo una alternativa puede ser la recomendada.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'currency_code.regex' => 'La moneda debe tener tres letras.',
            'options.min' => 'Agregá al menos una alternativa.',
            'options.*.label.required' => 'Nombrá cada alternativa.',
            'options.*.lines.min' => 'Cada alternativa necesita una línea.',
            'options.*.lines.*.type.enum' => 'El tipo de línea no es válido.',
            'options.*.lines.*.description.required' =>
                'Describí cada concepto.',
            'options.*.lines.*.quantity.regex' =>
                'La cantidad debe ser positiva y tener hasta 6 decimales.',
            'options.*.lines.*.unit_price.regex' =>
                'El precio debe ser positivo y tener hasta 2 decimales.',
            'idempotency_key.regex' => 'La clave de seguridad no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
