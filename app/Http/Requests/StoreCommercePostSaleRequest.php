<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleIntent;
use App\Enums\CommerceSaleLineType;
use App\Models\CommerceSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommercePostSaleRequest extends FormRequest
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

        $sale = $this->route(
            'commerceSale'
        );

        if (! $sale instanceof CommerceSale) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $sale->organization_id
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
                        'commerce_sale_line_id' =>
                            filled(
                                $line[
                                    'commerce_sale_line_id'
                                ] ?? null
                            )
                                ? (int) $line[
                                    'commerce_sale_line_id'
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
            'intent' =>
                strtolower(
                    trim(
                        (string) $this->input(
                            'intent'
                        )
                    )
                ),
            'reason' =>
                trim(
                    (string) $this->input(
                        'reason'
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
            'idempotency_key' =>
                trim(
                    (string) $this->input(
                        'idempotency_key'
                    )
                ),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $organizationId =
            app(CurrentOrganization::class)
                ->id($this->user());

        /** @var CommerceSale $sale */
        $sale = $this->route(
            'commerceSale'
        );

        return [
            'intent' => [
                'required',
                Rule::enum(
                    CommercePostSaleIntent::class
                ),
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:500',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
            'lines' => [
                'required',
                'array',
                'min:1',
            ],
            'lines.*.commerce_sale_line_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(
                    'commerce_sale_lines',
                    'id'
                )
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_sale_id',
                        $sale->id
                    )
                    ->where(
                        'line_type',
                        CommerceSaleLineType::Product
                            ->value
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
                'Seleccioná al menos un producto vendido.',
            'lines.min' =>
                'Seleccioná al menos un producto vendido.',
            'lines.*.quantity.regex' =>
                'La cantidad debe ser positiva y puede tener hasta 6 decimales.',
        ];
    }
}
