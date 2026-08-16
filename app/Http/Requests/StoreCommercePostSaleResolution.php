<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Models\CommercePostSaleReceiptLine;
use App\Models\CommercePostSaleRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommercePostSaleResolution extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'resolve-commerce-post-sale'
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
                        'commerce_post_sale_receipt_line_id' =>
                            filled(
                                $line[
                                    'commerce_post_sale_receipt_line_id'
                                ] ?? null
                            )
                                ? (int) $line[
                                    'commerce_post_sale_receipt_line_id'
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
                        'recognized_amount' =>
                            str_replace(
                                ',',
                                '.',
                                trim(
                                    (string) (
                                        $line[
                                            'recognized_amount'
                                        ] ?? ''
                                    )
                                )
                            ),
                        'adjustment_reason' =>
                            filled(
                                $line[
                                    'adjustment_reason'
                                ] ?? null
                            )
                                ? trim(
                                    (string) $line[
                                        'adjustment_reason'
                                    ]
                                )
                                : null,
                    ]
                )
                ->values()
                ->all();

        $this->merge([
            'outcome' =>
                strtolower(
                    trim(
                        (string) $this->input(
                            'outcome'
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
            'preferred_original_payment_id' =>
                filled(
                    $this->input(
                        'preferred_original_payment_id'
                    )
                )
                    ? (int) $this->input(
                        'preferred_original_payment_id'
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

        /** @var CommercePostSaleRequest $postSale */
        $postSale =
            $this->route(
                'commercePostSaleRequest'
            );

        $receiptLineIds =
            CommercePostSaleReceiptLine::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->whereHas(
                    'receipt',
                    fn ($query) =>
                        $query->where(
                            'commerce_post_sale_request_id',
                            $postSale->id
                        )
                )
                ->pluck('id')
                ->all();

        return [
            'outcome' => [
                'required',
                Rule::enum(
                    CommercePostSaleResolutionOutcome::class
                ),
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'preferred_original_payment_id' => [
                'nullable',
                'integer',
                Rule::exists(
                    'commerce_payments',
                    'id'
                )
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_sale_id',
                        $postSale
                            ->commerce_sale_id
                    ),
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
            'lines.*.commerce_post_sale_receipt_line_id' => [
                'required',
                'integer',
                'distinct',
                Rule::in(
                    $receiptLineIds
                ),
            ],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:32',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'lines.*.recognized_amount' => [
                'required',
                'string',
                'max:16',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'lines.*.adjustment_reason' => [
                'nullable',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' =>
                'Seleccioná al menos una línea físicamente recibida.',
            'lines.min' =>
                'Seleccioná al menos una línea físicamente recibida.',
            'lines.*.commerce_post_sale_receipt_line_id.in' =>
                'La línea seleccionada no pertenece a una recepción física de este expediente.',
            'lines.*.quantity.regex' =>
                'La cantidad a resolver debe ser positiva y puede tener hasta 6 decimales.',
            'lines.*.recognized_amount.regex' =>
                'El valor reconocido debe tener formato monetario con hasta 2 decimales.',
            'preferred_original_payment_id.exists' =>
                'El medio original preferido debe pertenecer a esta venta.',
        ];
    }
}
