<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\CommercePostSaleResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommercePostSaleExchangeSelection extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'select-commerce-post-sale-exchange'
            )
        ) {
            return false;
        }

        $resolution =
            $this->route(
                'commercePostSaleResolution'
            );

        if (
            ! $resolution
                instanceof CommercePostSaleResolution
        ) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $resolution
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
                        'catalog_product_id' =>
                            filled(
                                $line[
                                    'catalog_product_id'
                                ] ?? null
                            )
                                ? (int) $line[
                                    'catalog_product_id'
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
            'lines.*.catalog_product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(
                    'catalog_products',
                    'id'
                )->where(
                    'active',
                    true
                ),
            ],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:32',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' =>
                'Seleccioná al menos un producto de reemplazo.',
            'lines.min' =>
                'Seleccioná al menos un producto de reemplazo.',
            'lines.*.quantity.regex' =>
                'La cantidad de reemplazo debe ser positiva y puede tener hasta 6 decimales.',
        ];
    }
}
