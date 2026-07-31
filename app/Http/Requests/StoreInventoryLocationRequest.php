<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryLocationType;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'manage-inventory-locations'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::of(
                (string) $this->input('name')
            )->squish()->toString(),
            'type' => Str::of(
                (string) $this->input('type')
            )->trim()->lower()->toString(),
            'parent_id' => $this->filled('parent_id')
                ? (int) $this->input('parent_id')
                : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(
            CurrentOrganization::class
        )->id();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'type' => [
                'required',
                Rule::enum(InventoryLocationType::class),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where(
                        fn (Builder $query) => $query->where(
                            'organization_id',
                            $organizationId
                        )
                    ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' =>
                'El nombre de la ubicación es obligatorio.',
            'name.max' =>
                'El nombre no puede superar los 255 caracteres.',
            'type.required' =>
                'El tipo de ubicación es obligatorio.',
            'type.enum' =>
                'El tipo de ubicación seleccionado no es válido.',
            'parent_id.exists' =>
                'La ubicación superior no pertenece a la organización activa.',
        ];
    }
}
