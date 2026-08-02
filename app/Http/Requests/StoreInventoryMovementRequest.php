<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'draft-inventory-movements'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $lines = array_values(array_map(
                fn (mixed $line): mixed => is_array($line)
                    ? $this->normalizeLine($line)
                    : $line,
                $lines
            ));
        }

        $this->merge([
            'type' => Str::of((string) $this->input('type'))
                ->trim()->lower()->toString(),
            'effective_at' => trim(
                (string) $this->input('effective_at')
            ),
            'reason' => Str::of((string) $this->input('reason'))
                ->squish()->toString(),
            'source_reference' => Str::of(
                (string) $this->input('source_reference')
            )->squish()->toString() ?: null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $activeLocation = fn () => Rule::exists(
            'inventory_locations',
            'id'
        )->where(
            fn (Builder $query): Builder => $query
                ->where('organization_id', $organizationId)
                ->where('active', true)
        );

        return [
            'type' => [
                'required',
                Rule::enum(InventoryMovementType::class),
                Rule::notIn([
                    InventoryMovementType::Reversal->value,
                ]),
            ],
            'effective_at' => ['required', 'date'],
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
            'source_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^inventory-ui:[0-9a-f-]{36}$/',
            ],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.catalog_product_id' => [
                'required',
                'integer',
                Rule::exists('catalog_products', 'id')->where(
                    fn (Builder $query): Builder => $query->where(
                        'active',
                        true
                    )
                ),
            ],
            'lines.*.condition' => [
                'required',
                Rule::enum(InventoryCondition::class),
            ],
            'lines.*.entered_quantity' => [
                'required',
                'numeric',
                'decimal:0,6',
                'gt:0',
            ],
            'lines.*.source_location_id' => [
                'nullable',
                'integer',
                $activeLocation(),
            ],
            'lines.*.destination_location_id' => [
                'nullable',
                'integer',
                $activeLocation(),
            ],
            'lines.*.notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = InventoryMovementType::tryFrom(
                (string) $this->input('type')
            );

            if (! $type || $type === InventoryMovementType::Reversal) {
                return;
            }

            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $source = $line['source_location_id'] ?? null;
                $destination = $line['destination_location_id'] ?? null;

                if ($type->requiresSource() && $source === null) {
                    $validator->errors()->add(
                        "lines.$index.source_location_id",
                        'Este tipo de movimiento requiere una ubicación de origen.'
                    );
                }

                if (! $type->allowsSource() && $source !== null) {
                    $validator->errors()->add(
                        "lines.$index.source_location_id",
                        'Este tipo de movimiento no admite una ubicación de origen.'
                    );
                }

                if ($type->requiresDestination() && $destination === null) {
                    $validator->errors()->add(
                        "lines.$index.destination_location_id",
                        'Este tipo de movimiento requiere una ubicación de destino.'
                    );
                }

                if (! $type->allowsDestination() && $destination !== null) {
                    $validator->errors()->add(
                        "lines.$index.destination_location_id",
                        'Este tipo de movimiento no admite una ubicación de destino.'
                    );
                }

                if (
                    $source !== null
                    && $destination !== null
                    && (int) $source === (int) $destination
                ) {
                    $validator->errors()->add(
                        "lines.$index.destination_location_id",
                        'El origen y el destino deben ser diferentes.'
                    );
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de movimiento es obligatorio.',
            'type.enum' => 'El tipo de movimiento no es válido.',
            'type.not_in' => 'Los reversos se crean mediante correcciones.',
            'effective_at.required' => 'La fecha efectiva es obligatoria.',
            'effective_at.date' => 'La fecha efectiva no es válida.',
            'reason.required' => 'El motivo del movimiento es obligatorio.',
            'reason.min' => 'El motivo debe contener al menos 5 caracteres.',
            'lines.required' => 'El movimiento requiere al menos una línea.',
            'lines.min' => 'El movimiento requiere al menos una línea.',
            'lines.max' => 'Un movimiento admite hasta 20 líneas.',
            'lines.*.catalog_product_id.required' =>
                'Cada línea requiere un producto.',
            'lines.*.catalog_product_id.exists' =>
                'El producto seleccionado no existe o está inactivo.',
            'lines.*.condition.required' =>
                'Cada línea requiere una condición.',
            'lines.*.condition.enum' =>
                'La condición seleccionada no es válida.',
            'lines.*.entered_quantity.required' =>
                'Cada línea requiere una cantidad.',
            'lines.*.entered_quantity.decimal' =>
                'La cantidad admite hasta 6 decimales.',
            'lines.*.entered_quantity.gt' =>
                'La cantidad debe ser mayor que cero.',
            'lines.*.source_location_id.exists' =>
                'El origen no pertenece a la organización activa.',
            'lines.*.destination_location_id.exists' =>
                'El destino no pertenece a la organización activa.',
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function normalizeLine(array $line): array
    {
        return [
            'catalog_product_id' => filled(
                $line['catalog_product_id'] ?? null
            ) ? (int) $line['catalog_product_id'] : null,
            'condition' => Str::of(
                (string) ($line['condition'] ?? '')
            )->trim()->lower()->toString(),
            'entered_quantity' => str_replace(
                ',',
                '.',
                trim((string) ($line['entered_quantity'] ?? ''))
            ),
            'source_location_id' => filled(
                $line['source_location_id'] ?? null
            ) ? (int) $line['source_location_id'] : null,
            'destination_location_id' => filled(
                $line['destination_location_id'] ?? null
            ) ? (int) $line['destination_location_id'] : null,
            'notes' => Str::of((string) ($line['notes'] ?? ''))
                ->squish()->toString() ?: null,
        ];
    }
}
