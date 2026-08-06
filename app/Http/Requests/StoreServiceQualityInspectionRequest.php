<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceQualityInspectionRequest extends FormRequest
{
    private const CHECK_CODES = [
        'power',
        'charging',
        'primary_function',
        'connectivity',
        'physical_condition',
        'accessories',
    ];

    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('inspect-service-quality')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $checks = collect((array) $this->input('checks', []))
            ->filter(fn (mixed $check): bool => is_array($check))
            ->map(fn (array $check): array => [
                'code' => strtolower(trim(
                    (string) ($check['code'] ?? '')
                )),
                'passed' => filter_var(
                    $check['passed'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
                'notes' => $this->optional(
                    (string) ($check['notes'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $this->merge([
            'checks' => $checks,
            'condition_notes' => trim(
                (string) $this->input('condition_notes')
            ),
            'accessories_snapshot' => trim(
                (string) $this->input('accessories_snapshot')
            ),
            'rework_reason' => $this->optional(
                (string) $this->input('rework_reason')
            ),
            'notes' => $this->optional(
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
            'checks' => [
                'required',
                'array',
                'size:'.count(self::CHECK_CODES),
            ],
            'checks.*.code' => [
                'required',
                'string',
                'distinct',
                Rule::in(self::CHECK_CODES),
            ],
            'checks.*.passed' => ['required', 'boolean'],
            'checks.*.notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'condition_notes' => [
                'required',
                'string',
                'max:5000',
            ],
            'accessories_snapshot' => [
                'required',
                'string',
                'max:5000',
            ],
            'rework_reason' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:quality-inspection:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $checks = collect((array) $this->input('checks'));
            $codes = $checks->pluck('code')->sort()->values()->all();
            $expected = collect(self::CHECK_CODES)
                ->sort()
                ->values()
                ->all();

            if ($codes !== $expected) {
                $validator->errors()->add(
                    'checks',
                    'El control debe incluir el protocolo completo.'
                );
            }

            $failed = $checks->contains(
                fn (array $check): bool => ! $check['passed']
            );

            if ($failed && $this->input('rework_reason') === null) {
                $validator->errors()->add(
                    'rework_reason',
                    'Indicá el retrabajo requerido por las pruebas fallidas.'
                );
            }

            if (! $failed && $this->input('rework_reason') !== null) {
                $validator->errors()->add(
                    'rework_reason',
                    'Un control aprobado no debe declarar retrabajo.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'checks.size' => 'El control debe responder todas las comprobaciones.',
            'checks.*.code.distinct' => 'Una comprobación está repetida.',
            'checks.*.code.in' => 'La comprobación no pertenece al protocolo vigente.',
            'condition_notes.required' => 'Describí la condición final del equipo.',
            'accessories_snapshot.required' => 'Confirmá los accesorios y elementos en custodia.',
            'idempotency_key.regex' => 'La clave de seguridad del control no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
