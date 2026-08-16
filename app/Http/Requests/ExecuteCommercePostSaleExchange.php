<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Models\CommercePostSaleExchangeSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteCommercePostSaleExchange extends FormRequest
{
    public function authorize(): bool
    {
        $user =
            $this->user();

        if (
            ! $user
            || ! $user->can(
                'execute-commerce-post-sale-exchange'
            )
        ) {
            return false;
        }

        $selection =
            $this->route(
                'exchangeSelection'
            );

        if (
            ! $selection
                instanceof CommercePostSaleExchangeSelection
        ) {
            return false;
        }

        $organizationId =
            app(CurrentOrganization::class)
                ->id($user);

        abort_unless(
            (int) $selection
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
                ->map(
                    function (array $line): array {
                        $source =
                            trim(
                                (string) (
                                    $line[
                                        'source_balance'
                                    ] ?? ''
                                )
                            );

                        [$location, $condition] =
                            array_pad(
                                explode(
                                    '|',
                                    $source,
                                    2
                                ),
                                2,
                                null
                            );

                        return [
                            'commerce_post_sale_exchange_selection_line_id' =>
                                filled(
                                    $line[
                                        'commerce_post_sale_exchange_selection_line_id'
                                    ] ?? null
                                )
                                    ? (int) $line[
                                        'commerce_post_sale_exchange_selection_line_id'
                                    ]
                                    : null,
                            'source_location_id' =>
                                filled($location)
                                    ? (int) $location
                                    : null,
                            'condition' =>
                                filled($condition)
                                    ? strtolower(
                                        trim(
                                            (string) $condition
                                        )
                                    )
                                    : null,
                        ];
                    }
                )
                ->values()
                ->all();

        $payments =
            collect(
                (array) $this->input(
                    'payments',
                    []
                )
            )
                ->filter(
                    fn (mixed $payment): bool =>
                        is_array($payment)
                )
                ->filter(
                    fn (array $payment): bool =>
                        filter_var(
                            $payment['selected']
                                ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        )
                )
                ->map(
                    fn (array $payment): array => [
                        'method' =>
                            strtolower(
                                trim(
                                    (string) (
                                        $payment[
                                            'method'
                                        ] ?? ''
                                    )
                                )
                            ),
                        'amount' =>
                            str_replace(
                                ',',
                                '.',
                                trim(
                                    (string) (
                                        $payment[
                                            'amount'
                                        ] ?? ''
                                    )
                                )
                            ),
                        'financial_account_id' =>
                            filled(
                                $payment[
                                    'financial_account_id'
                                ] ?? null
                            )
                                ? (int) $payment[
                                    'financial_account_id'
                                ]
                                : null,
                        'reference' =>
                            filled(
                                $payment[
                                    'reference'
                                ] ?? null
                            )
                                ? trim(
                                    (string) $payment[
                                        'reference'
                                    ]
                                )
                                : null,
                        'tendered_amount' =>
                            filled(
                                $payment[
                                    'tendered_amount'
                                ] ?? null
                            )
                                ? str_replace(
                                    ',',
                                    '.',
                                    trim(
                                        (string) $payment[
                                            'tendered_amount'
                                        ]
                                    )
                                )
                                : null,
                        'notes' =>
                            filled(
                                $payment[
                                    'notes'
                                ] ?? null
                            )
                                ? trim(
                                    (string) $payment[
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
            'lines' =>
                $lines,
            'payments' =>
                $payments,
        ]);
    }

    public function rules(): array
    {
        $organizationId =
            app(CurrentOrganization::class)
                ->id($this->user());

        /** @var CommercePostSaleExchangeSelection $selection */
        $selection =
            $this->route(
                'exchangeSelection'
            );

        $lineIds =
            $selection->lines()
                ->pluck('id')
                ->all();

        return [
            'confirm_execution' => [
                'accepted',
            ],
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
            'lines.*.commerce_post_sale_exchange_selection_line_id' => [
                'required',
                'integer',
                'distinct',
                Rule::in($lineIds),
            ],
            'lines.*.source_location_id' => [
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
            'lines.*.condition' => [
                'required',
                Rule::enum(
                    InventoryCondition::class
                ),
            ],
            'payments' => [
                'array',
            ],
            'payments.*.method' => [
                'required',
                Rule::enum(
                    CommercePaymentMethod::class
                ),
            ],
            'payments.*.amount' => [
                'required',
                'string',
                'max:16',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'payments.*.financial_account_id' => [
                'nullable',
                'integer',
                Rule::exists(
                    'financial_accounts',
                    'id'
                )
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'currency_code',
                        $selection
                            ->currency_code
                    )
                    ->where(
                        'active',
                        true
                    ),
            ],
            'payments.*.reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payments.*.tendered_amount' => [
                'nullable',
                'string',
                'max:16',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
            ],
            'payments.*.notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }


    public function withValidator(
        \Illuminate\Validation\Validator $validator
    ): void {
        $validator->after(
            function (
                \Illuminate\Validation\Validator $validator
            ): void {
                foreach (
                    (array) $this->input(
                        'payments',
                        []
                    )
                    as $index => $payment
                ) {
                    if (! is_array($payment)) {
                        continue;
                    }

                    $method =
                        CommercePaymentMethod::tryFrom(
                            (string) (
                                $payment['method']
                                ?? ''
                            )
                        );

                    if (
                        $method
                            === CommercePaymentMethod::AccountCredit
                    ) {
                        if (
                            filled(
                                $payment[
                                    'financial_account_id'
                                ] ?? null
                            )
                        ) {
                            $validator
                                ->errors()
                                ->add(
                                    "payments.{$index}.financial_account_id",
                                    'El saldo a favor no utiliza cuenta financiera.'
                                );
                        }

                        if (
                            filled(
                                $payment[
                                    'reference'
                                ] ?? null
                            )
                            || filled(
                                $payment[
                                    'tendered_amount'
                                ] ?? null
                            )
                        ) {
                            $validator
                                ->errors()
                                ->add(
                                    "payments.{$index}.method",
                                    'El saldo a favor no admite referencia manual ni efectivo entregado.'
                                );
                        }

                        continue;
                    }

                    if (
                        $method !== null
                        && blank(
                            $payment[
                                'financial_account_id'
                            ] ?? null
                        )
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                "payments.{$index}.financial_account_id",
                                'El medio de pago requiere una cuenta financiera activa.'
                            );
                    }
                }
            }
        );
    }

    public function messages(): array
    {
        return [
            'confirm_execution.accepted' =>
                'Confirmá explícitamente la entrega de mercadería y sus efectos económicos.',
            'lines.*.source_location_id.exists' =>
                'Una ubicación de origen no está activa o no pertenece a la organización.',
        ];
    }
}
