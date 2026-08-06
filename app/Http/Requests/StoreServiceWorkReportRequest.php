<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceWorkOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServiceWorkReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');
        $work = $this->route('serviceWorkItem');

        return $user?->can('execute-service-work')
            && $order
            && $work
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user)
            && (int) $work->organization_id
                === (int) $order->organization_id
            && (int) $work->service_order_id === (int) $order->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'outcome' => Str::lower(
                trim((string) $this->input('outcome'))
            ),
            'result_summary' => trim(
                (string) $this->input('result_summary')
            ),
            'work_performed' => trim(
                (string) $this->input('work_performed')
            ),
            'unresolved_reason' => $this->optional(
                (string) $this->input('unresolved_reason')
            ),
            'warranty_days' => filled($this->input('warranty_days'))
                ? (int) $this->input('warranty_days')
                : null,
            'warranty_terms' => $this->optional(
                (string) $this->input('warranty_terms')
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
                Rule::enum(ServiceWorkOutcome::class),
            ],
            'result_summary' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
            'work_performed' => [
                'required',
                'string',
                'min:5',
                'max:5000',
            ],
            'unresolved_reason' => [
                'nullable',
                'required_if:outcome,unresolved',
                'prohibited_unless:outcome,unresolved',
                'string',
                'min:5',
                'max:5000',
            ],
            'warranty_days' => [
                'nullable',
                'prohibited_if:outcome,unresolved',
                'integer',
                'min:0',
                'max:3650',
            ],
            'warranty_terms' => [
                'nullable',
                'prohibited_if:outcome,unresolved',
                'string',
                'max:5000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:work-report:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.enum' => 'El resultado técnico no es válido.',
            'result_summary.required' => 'Resumí el resultado obtenido.',
            'work_performed.required' => 'Detallá el trabajo efectivamente realizado.',
            'unresolved_reason.required_if' => 'Explicá por qué el trabajo quedó sin solución.',
            'warranty_days.prohibited_if' => 'Un trabajo sin solución no puede otorgar garantía.',
            'warranty_terms.prohibited_if' => 'Un trabajo sin solución no puede otorgar garantía.',
            'idempotency_key.regex' => 'La clave de seguridad del resultado no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
