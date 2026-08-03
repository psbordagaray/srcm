<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceFindingSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServiceDiagnosticRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('record-service-diagnostics')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $riskPresent = $this->boolean('data_risk_present');
        $findings = collect((array) $this->input('findings', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'severity' => Str::lower(trim((string) ($row['severity'] ?? ''))),
                'category' => Str::squish((string) ($row['category'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'evidence_notes' => $this->optional(
                    (string) ($row['evidence_notes'] ?? '')
                ),
            ])
            ->filter(fn (array $row): bool =>
                $row['category'] !== '' || $row['description'] !== ''
            )->values()->all();

        $this->merge([
            'summary' => trim((string) $this->input('summary')),
            'recommendation' => trim((string) $this->input('recommendation')),
            'data_risk_present' => $riskPresent,
            'data_risk_notes' => $riskPresent
                ? $this->optional((string) $this->input('data_risk_notes'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'findings' => $findings,
        ]);
    }

    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'min:5', 'max:5000'],
            'recommendation' => ['required', 'string', 'min:5', 'max:5000'],
            'data_risk_present' => ['required', 'boolean'],
            'data_risk_notes' => [
                'nullable',
                'required_if:data_risk_present,1',
                'string',
                'max:5000',
            ],
            'findings' => ['required', 'array', 'min:1', 'max:20'],
            'findings.*.severity' => [
                'required',
                Rule::enum(ServiceFindingSeverity::class),
            ],
            'findings.*.category' => ['required', 'string', 'max:100'],
            'findings.*.description' => [
                'required',
                'string',
                'min:3',
                'max:5000',
            ],
            'findings.*.evidence_notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:diagnostic:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'summary.required' => 'Describí la conclusión del diagnóstico.',
            'recommendation.required' => 'Indicá la recomendación técnica.',
            'data_risk_notes.required_if' =>
                'Describí el riesgo identificado sobre los datos.',
            'findings.min' => 'Registrá al menos un hallazgo técnico.',
            'findings.*.severity.enum' => 'La severidad no es válida.',
            'findings.*.category.required' => 'Indicá la categoría del hallazgo.',
            'findings.*.description.required' => 'Describí el hallazgo.',
            'idempotency_key.regex' => 'La clave de seguridad no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
