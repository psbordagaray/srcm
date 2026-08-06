<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceWorkExecutionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServiceWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('serviceOrder');

        return $user?->can('plan-service-work')
            && $order
            && (int) $order->organization_id
                === app(CurrentOrganization::class)->id($user);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => Str::squish((string) $this->input('title')),
            'description' => trim((string) $this->input('description')),
            'execution_mode' => Str::lower(
                trim((string) $this->input('execution_mode'))
            ),
            'assigned_user_id' => filled($this->input('assigned_user_id'))
                ? (int) $this->input('assigned_user_id')
                : null,
            'provider_business_party_id' => filled($this->input('provider_business_party_id'))
                    ? (int) $this->input('provider_business_party_id')
                    : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id($this->user());

        return [
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'execution_mode' => [
                'required',
                Rule::enum(ServiceWorkExecutionMode::class),
            ],
            'assigned_user_id' => [
                'nullable',
                'integer',
                'required_if:execution_mode,internal',
                'prohibited_unless:execution_mode,internal',
                Rule::exists('organization_memberships', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'provider_business_party_id' => [
                'nullable',
                'integer',
                'required_if:execution_mode,external',
                'prohibited_unless:execution_mode,external',
                Rule::exists('business_parties', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:work-plan:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Indicá el nombre del trabajo.',
            'description.required' => 'Describí el alcance técnico.',
            'execution_mode.enum' => 'La modalidad de ejecución no es válida.',
            'assigned_user_id.required_if' => 'Seleccioná un responsable interno.',
            'assigned_user_id.exists' => 'El responsable no pertenece a la organización activa.',
            'provider_business_party_id.required_if' => 'Seleccioná el especialista externo.',
            'provider_business_party_id.exists' => 'El especialista no pertenece a la organización activa.',
            'idempotency_key.regex' => 'La clave de seguridad del trabajo no es válida.',
        ];
    }
}
