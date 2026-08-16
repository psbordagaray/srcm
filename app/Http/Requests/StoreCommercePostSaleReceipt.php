<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Models\CommercePostSaleRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommercePostSaleReceipt extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'record-commerce-post-sale'
            )
        ) {
            return false;
        }

        $postSale =
            $this->route(
                'commercePostSaleRequest'
            );

        if (
            ! $postSale
                instanceof CommercePostSaleRequest
        ) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $postSale
                ->organization_id
                === $organizationId,
            404
        );

        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines =
            collect(
                (array) $this->input(
                    'lines',
                    []
                )
            )
                ->filter(
                    fn (mixed $line): bool =>
                        is_array($line)
                )
                ->filter(
                    fn (array $line): bool =>
                        filter_var(
                            $line['selected']
                                ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        )
                )
                ->map(
                    fn (array $line): array => [
                        'commerce_post_sale_request_line_id' =>
                            filled(
                                $line[
                                    'commerce_post_sale_request_line_id'
                                ] ?? null
                            )
                                ? (int) $line[
                                    'commerce_post_sale_request_line_id'
                                ]
                                : null,
                        'quantity' =>
                            str_replace(
                                ',',
                                '.',
                                trim(
                                    (string) (
                                        $line[
                                            'quantity'
                                        ] ?? ''
                                    )
                                )
                            ),
                        'condition' =>
                            strtolower(
                                trim(
                                    (string) (
                                        $line[
                                            'condition'
                                        ] ?? ''
                                    )
                                )
                            ),
                        'destination_location_id' =>
                            filled(
                                $line[
                                    'destination_location_id'
                                ] ?? null
                            )
                                ? (int) $line[
                                    'destination_location_id'
                                ]
                                : null,
                        'notes' =>
                            filled(
                                $line['notes']
                                    ?? null
                            )
                                ? trim(
                                    (string) $line[
                                        'notes'
                                    ]
                                )
                                : null,
                    ]
                )
                ->values()
                ->all();

        $this->merge([
            'idempotency_key' =>
                trim(
                    (string) $this->input(
                        'idempotency_key'
                    )
                ),
            'notes' =>
                filled(
                    $this->input('notes')
                )
                    ? trim(
                        (string) $this->input(
                            'notes'
                        )
                    )
                    : null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $organizationId =
            app(CurrentOrganization::class)
                ->id($this->user());

        /** @var CommercePostSaleRequest $postSale */
        $postSale =
            $this->route(
                'commercePostSaleRequest'
            );

        return [
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'lines' => [
                'required',
                'array',
                'min:1',
            ],
            'lines.*.commerce_post_sale_request_line_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(
                    'commerce_post_sale_request_lines',
                    'id'
                )
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_post_sale_request_id',
                        $postSale->id
                    ),
            ],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:32',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'lines.*.condition' => [
                'required',
                Rule::enum(
                    InventoryCondition::class
                ),
            ],
            'lines.*.destination_location_id' => [
                'required',
                'integer',
                Rule::exists(
                    'inventory_locations',
                    'id'
                )
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'active',
                        true
                    ),
            ],
            'lines.*.notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' =>
                'Seleccioná al menos una línea pendiente de recepción.',
            'lines.min' =>
                'Seleccioná al menos una línea pendiente de recepción.',
            'lines.*.quantity.regex' =>
                'La cantidad recibida debe ser positiva y puede tener hasta 6 decimales.',
            'lines.*.destination_location_id.exists' =>
                'La ubicación de recepción debe estar activa y pertenecer a la organización.',
        ];
    }
}
