<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'record-customer-collections'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $allocations = collect(
            (array) $this->input('allocations', [])
        )
            ->filter(fn (mixed $allocation): bool =>
                is_array($allocation)
            )
            ->filter(fn (array $allocation): bool =>
                filled($allocation['amount'] ?? null)
            )
            ->map(fn (array $allocation): array => [
                'customer_receivable_id' => filled(
                    $allocation['customer_receivable_id']
                    ?? null
                )
                    ? (int) $allocation[
                        'customer_receivable_id'
                    ]
                    : null,
                'amount' => $this->money(
                    (string) (
                        $allocation['amount'] ?? ''
                    )
                ),
            ])
            ->values()
            ->all();

        $this->merge([
            'currency_code' => strtoupper(trim(
                (string) $this->input(
                    'currency_code',
                    'ARS'
                )
            )),
            'method' => strtolower(trim(
                (string) $this->input('method', '')
            )),
            'financial_account_id' => filled(
                $this->input('financial_account_id')
            )
                ? (int) $this->input(
                    'financial_account_id'
                )
                : null,
            'amount' => $this->money(
                (string) $this->input('amount', '')
            ),
            'tendered_amount' => $this->optional(
                $this->money(
                    (string) $this->input(
                        'tendered_amount',
                        ''
                    )
                )
            ),
            'reference' => $this->optional(
                (string) $this->input('reference', '')
            ),
            'notes' => $this->optional(
                (string) $this->input('notes', '')
            ),
            'allocations' => $allocations,
            'idempotency_key' => trim(
                (string) $this->input(
                    'idempotency_key',
                    ''
                )
            ),
            'retain_excess_as_credit' =>
                $this->boolean(
                    'retain_excess_as_credit'
                ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(
            CurrentOrganization::class
        )->id($this->user());

        $money = [
            'required',
            'string',
            'max:18',
            'regex:/^(?=.*[1-9])\d{1,14}(?:[.,]\d{1,2})?$/',
        ];

        return [
            'currency_code' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'method' => [
                'required',
                Rule::enum(CommercePaymentMethod::class),
                Rule::notIn([
                    CommercePaymentMethod::AccountCredit->value,
                ]),
            ],
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')
                    ->where(
                        fn ($query) => $query
                            ->where(
                                'organization_id',
                                $organizationId
                            )
                            ->where('active', true)
                    ),
            ],
            'amount' => $money,
            'tendered_amount' => [
                'nullable',
                'string',
                'max:18',
                'regex:/^(?=.*[1-9])\d{1,14}(?:[.,]\d{1,2})?$/',
            ],
            'reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'allocations' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'allocations.*.customer_receivable_id' => [
                'required',
                'integer',
                'distinct',
            ],
            'allocations.*.amount' => $money,
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
            'retain_excess_as_credit' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'method.not_in' =>
                'El saldo a favor no puede usarse como medio de cobranza de CxC.',
            'allocations.min' =>
                'Aplicá la cobranza al menos a una deuda.',
            'allocations.*.customer_receivable_id.distinct' =>
                'Una deuda no puede repetirse en la cobranza.',
        ];
    }

    private function money(string $value): string
    {
        return str_replace(
            ',',
            '.',
            trim($value)
        );
    }

    private function optional(?string $value): ?string
    {
        $value = $value === null
            ? null
            : trim($value);

        return $value === ''
            ? null
            : $value;
    }
}
