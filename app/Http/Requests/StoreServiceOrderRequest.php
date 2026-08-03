<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceIdentifierType;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create-service-orders') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $identifiers = collect((array) $this->input('identifiers', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'type' => Str::of((string) ($row['type'] ?? ''))
                    ->trim()->lower()->toString(),
                'value' => Str::of((string) ($row['value'] ?? ''))
                    ->squish()->toString(),
            ])
            ->filter(fn (array $row): bool =>
                $row['type'] !== '' || $row['value'] !== ''
            )
            ->values()
            ->all();

        $this->merge([
            'asset_type' => Str::of((string) $this->input('asset_type'))
                ->trim()->lower()->toString(),
            'brand_name' => Str::of((string) $this->input('brand_name'))
                ->squish()->toString(),
            'model_name' => Str::of((string) $this->input('model_name'))
                ->squish()->toString(),
            'color' => $this->optional('color'),
            'customer_business_party_id' => $this->integerOrNull(
                'customer_business_party_id'
            ),
            'customer_name' => $this->optional('customer_name'),
            'owner_business_party_id' => $this->integerOrNull(
                'owner_business_party_id'
            ),
            'owner_name' => $this->optional('owner_name'),
            'intake_location_id' => $this->integerOrNull(
                'intake_location_id'
            ),
            'customer_reported_issue' => trim(
                (string) $this->input('customer_reported_issue')
            ),
            'intake_observations' => $this->optionalMultiline(
                'intake_observations'
            ),
            'received_accessories' => $this->optionalMultiline(
                'received_accessories'
            ),
            'contact_available' => $this->boolean('contact_available'),
            'contact_reference' => $this->optional('contact_reference'),
            'promised_at' => $this->optional('promised_at'),
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'identifiers' => $identifiers,
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $party = fn () => Rule::exists('business_parties', 'id')
            ->where(fn (Builder $query): Builder => $query->where(
                'organization_id',
                $organizationId
            ));
        $location = Rule::exists('inventory_locations', 'id')
            ->where(fn (Builder $query): Builder => $query
                ->where('organization_id', $organizationId)
                ->where('active', true));

        return [
            'asset_type' => ['required', Rule::enum(ServiceAssetType::class)],
            'brand_name' => ['required', 'string', 'max:255'],
            'model_name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:100'],
            'identifiers' => ['array', 'max:10'],
            'identifiers.*.type' => [
                'required',
                Rule::enum(ServiceIdentifierType::class),
            ],
            'identifiers.*.value' => [
                'required',
                'string',
                'max:255',
            ],
            'customer_business_party_id' => [
                'nullable',
                'integer',
                $party(),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'owner_business_party_id' => [
                'nullable',
                'integer',
                $party(),
            ],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'intake_location_id' => [
                'required',
                'integer',
                $location,
            ],
            'customer_reported_issue' => [
                'required',
                'string',
                'min:3',
                'max:5000',
            ],
            'intake_observations' => ['nullable', 'string', 'max:10000'],
            'received_accessories' => ['nullable', 'string', 'max:10000'],
            'contact_available' => ['required', 'boolean'],
            'contact_reference' => ['nullable', 'string', 'max:255'],
            'promised_at' => ['nullable', 'date', 'after:now'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                ! $this->input('customer_business_party_id')
                && ! $this->input('customer_name')
            ) {
                $validator->errors()->add(
                    'customer_name',
                    'Seleccioná una ficha o indicá quién entrega el equipo.'
                );
            }

            if (
                $this->boolean('contact_available')
                && ! $this->input('contact_reference')
            ) {
                $validator->errors()->add(
                    'contact_reference',
                    'Indicá el teléfono, correo u otro medio de contacto.'
                );
            }

            if (
                ! $this->boolean('contact_available')
                && $this->input('contact_reference')
            ) {
                $validator->errors()->add(
                    'contact_reference',
                    'Marcá que existe contacto o quitá la referencia.'
                );
            }

            foreach ((array) $this->input('identifiers', []) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (
                    ($row['type'] ?? null) === ServiceIdentifierType::Imei->value
                    && ! preg_match(
                        '/^\d{14,16}$/',
                        ServiceIdentifierType::Imei->normalize(
                            (string) ($row['value'] ?? '')
                        )
                    )
                ) {
                    $validator->errors()->add(
                        "identifiers.{$index}.value",
                        'El IMEI debe contener entre 14 y 16 dígitos.'
                    );
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'asset_type.required' => 'El tipo de equipo es obligatorio.',
            'asset_type.enum' => 'El tipo de equipo no es válido.',
            'brand_name.required' => 'La marca es obligatoria.',
            'model_name.required' => 'El modelo es obligatorio.',
            'identifiers.max' => 'Se admiten hasta 10 identificadores.',
            'identifiers.*.type.required' =>
                'Seleccioná el tipo de identificador.',
            'identifiers.*.type.enum' =>
                'El tipo de identificador no es válido.',
            'identifiers.*.value.required' =>
                'Ingresá el valor del identificador.',
            'customer_business_party_id.exists' =>
                'La ficha seleccionada no pertenece a la organización.',
            'owner_business_party_id.exists' =>
                'La ficha del propietario no pertenece a la organización.',
            'intake_location_id.required' =>
                'La ubicación de recepción es obligatoria.',
            'intake_location_id.exists' =>
                'La ubicación no pertenece a la organización o está inactiva.',
            'customer_reported_issue.required' =>
                'Registrá qué problema declara el cliente.',
            'customer_reported_issue.min' =>
                'La falla declarada debe ser más descriptiva.',
            'promised_at.after' =>
                'La fecha prometida debe ser posterior al momento actual.',
            'idempotency_key.regex' =>
                'La clave de seguridad de la recepción no es válida.',
        ];
    }

    private function optional(string $key): ?string
    {
        $value = Str::of((string) $this->input($key))
            ->squish()
            ->toString();

        return $value === '' ? null : $value;
    }

    private function optionalMultiline(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }

    private function integerOrNull(string $key): ?int
    {
        return filled($this->input($key))
            ? (int) $this->input($key)
            : null;
    }
}
