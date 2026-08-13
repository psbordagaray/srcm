<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCommerceSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record-commerce-sales')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $productLines = collect(
            (array) $this->input('product_lines', [])
        )
            ->filter(fn (mixed $line): bool => is_array($line))
            ->filter(fn (array $line): bool => filled($line['catalog_product_id'] ?? null)
                || filled($line['source_location_id'] ?? null)
                || filled($line['quantity'] ?? null)
            )
            ->map(fn (array $line): array => [
                'catalog_product_id' => filled(
                    $line['catalog_product_id'] ?? null
                )
                    ? (int) $line['catalog_product_id']
                    : null,
                'source_location_id' => filled(
                    $line['source_location_id'] ?? null
                )
                    ? (int) $line['source_location_id']
                    : null,
                'condition' => strtolower(trim(
                    (string) ($line['condition'] ?? '')
                )),
                'quantity' => trim(
                    (string) ($line['quantity'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $payments = collect((array) $this->input('payments', []))
            ->filter(fn (mixed $payment): bool => is_array($payment)
            )
            ->filter(fn (array $payment): bool => filled($payment['amount'] ?? null)
                || filled($payment['tendered_amount'] ?? null)
                || filled($payment['reference'] ?? null)
                || filled($payment['financial_account_id'] ?? null)
                || filled($payment['card_brand'] ?? null)
                || filled($payment['card_network'] ?? null)
                || filled($payment['card_last4'] ?? null)
                || filled($payment['installments'] ?? null)
                || filled($payment['processor'] ?? null)
                || filled($payment['external_operation_id'] ?? null)
                || filled($payment['authorization_code'] ?? null)
                || filled($payment['provider_status'] ?? null)
                || filled($payment['notes'] ?? null)
                || filled($payment['paid_at'] ?? null)
                || filled($payment['pan'] ?? null)
                || filled($payment['card_number'] ?? null)
                || filled($payment['cvv'] ?? null)
                || filled($payment['security_code'] ?? null)
            )
            ->map(fn (array $payment): array => [
                'method' => strtolower(trim(
                    (string) ($payment['method'] ?? '')
                )),
                'amount' => $this->money(
                    (string) ($payment['amount'] ?? '')
                ),
                'tendered_amount' => $this->optional(
                    $this->money(
                        (string) ($payment['tendered_amount'] ?? '')
                    )
                ),
                'reference' => $this->optional(
                    (string) ($payment['reference'] ?? '')
                ),
                'financial_account_id' => filled(
                    $payment['financial_account_id'] ?? null
                )
                    ? (int) $payment['financial_account_id']
                    : null,
                'card_brand' => $this->optional(
                    (string) ($payment['card_brand'] ?? '')
                ),
                'card_network' => $this->optional(
                    (string) ($payment['card_network'] ?? '')
                ),
                'card_last4' => $this->optional(
                    (string) ($payment['card_last4'] ?? '')
                ),
                'installments' => $this->optional(
                    (string) ($payment['installments'] ?? '')
                ),
                'processor' => $this->optional(
                    (string) ($payment['processor'] ?? '')
                ),
                'external_operation_id' => $this->optional(
                    (string) ($payment['external_operation_id'] ?? '')
                ),
                'authorization_code' => $this->optional(
                    (string) ($payment['authorization_code'] ?? '')
                ),
                'provider_status' => $this->optional(
                    (string) ($payment['provider_status'] ?? '')
                ),
                'pan' => $this->optional(
                    (string) ($payment['pan'] ?? '')
                ),
                'card_number' => $this->optional(
                    (string) ($payment['card_number'] ?? '')
                ),
                'cvv' => $this->optional(
                    (string) ($payment['cvv'] ?? '')
                ),
                'security_code' => $this->optional(
                    (string) ($payment['security_code'] ?? '')
                ),
                'notes' => $this->optional(
                    (string) ($payment['notes'] ?? '')
                ),
                'paid_at' => $this->optional(
                    (string) ($payment['paid_at'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $this->merge([
            'currency_code' => strtoupper(trim(
                (string) $this->input('currency_code', 'ARS')
            )),
            'service_order_id' => filled(
                $this->input('service_order_id')
            )
                ? (int) $this->input('service_order_id')
                : null,
            'customer_business_party_id' => filled(
                $this->input('customer_business_party_id')
            )
                ? (int) $this->input(
                    'customer_business_party_id'
                )
                : null,
            'customer_name' => $this->optional(
                (string) $this->input('customer_name')
            ),
            'customer_document' => $this->optional(
                (string) $this->input('customer_document')
            ),
            'notes' => $this->optional(
                (string) $this->input('notes')
            ),
            'sold_at' => $this->optional(
                (string) $this->input('sold_at')
            ),
            'product_lines' => $productLines,
            'payments' => $payments,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id(
            $this->user()
        );
        $positiveMoney = [
            'required',
            'string',
            'max:18',
            'regex:/^(?=.*[1-9])\d{1,14}(?:[.,]\d{1,2})?$/',
        ];
        $optionalPositiveMoney = [
            'nullable',
            'string',
            'max:18',
            'regex:/^(?=.*[1-9])\d{1,14}(?:[.,]\d{1,2})?$/',
        ];

        return [
            'currency_code' => [
                'required',
                'string',
                Rule::in(['ARS', 'USD']),
            ],
            'service_order_id' => [
                'nullable',
                'integer',
                Rule::exists('service_orders', 'id')
                    ->where('organization_id', $organizationId)
                    ->where(
                        'status',
                        ServiceOrderStatus::Delivered->value
                    ),
            ],
            'customer_business_party_id' => [
                'nullable',
                'integer',
                Rule::exists('business_parties', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'customer_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'customer_document' => [
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'sold_at' => ['prohibited'],
            'product_lines' => [
                'array',
                'max:20',
            ],
            'product_lines.*.catalog_product_id' => [
                'required',
                'integer',
                Rule::exists('catalog_products', 'id')
                    ->where('active', true),
            ],
            'product_lines.*.source_location_id' => [
                'required',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true),
            ],
            'product_lines.*.condition' => [
                'required',
                Rule::enum(InventoryCondition::class),
            ],
            'product_lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/',
            ],
            'payments' => [
                'required',
                'array',
                'min:1',
                'max:10',
            ],
            'payments.*.method' => [
                'required',
                Rule::enum(CommercePaymentMethod::class),
            ],
            'payments.*.amount' => $positiveMoney,
            'payments.*.tendered_amount' => $optionalPositiveMoney,
            'payments.*.financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('active', true)
                    ->where(
                        'currency_code',
                        (string) $this->input('currency_code')
                    ),
            ],
            'payments.*.reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payments.*.card_brand' => [
                'nullable',
                'string',
                'max:50',
            ],
            'payments.*.card_network' => [
                'nullable',
                'string',
                'max:50',
            ],
            'payments.*.card_last4' => [
                'nullable',
                'string',
                'regex:/^\d{4}$/',
            ],
            'payments.*.installments' => [
                'nullable',
                'integer',
                'min:1',
                'max:120',
            ],
            'payments.*.processor' => [
                'nullable',
                'string',
                'max:100',
            ],
            'payments.*.external_operation_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'payments.*.authorization_code' => [
                'nullable',
                'string',
                'max:100',
            ],
            'payments.*.provider_status' => [
                'nullable',
                'string',
                'max:50',
            ],
            'payments.*.pan' => ['prohibited'],
            'payments.*.card_number' => ['prohibited'],
            'payments.*.cvv' => ['prohibited'],
            'payments.*.security_code' => ['prohibited'],
            'payments.*.notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'payments.*.paid_at' => ['prohibited'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^service-ui:commerce-sale:[0-9a-f-]{36}$/',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $organizationId = app(CurrentOrganization::class)->id(
                $this->user()
            );
            $serviceOrderId = $this->input('service_order_id');
            $productLines = (array) $this->input(
                'product_lines',
                []
            );

            if ($serviceOrderId === null && $productLines === []) {
                $validator->errors()->add(
                    'product_lines',
                    'La venta requiere una reparación entregada o al menos un producto.'
                );
            }

            if ($serviceOrderId !== null) {
                $order = ServiceOrder::query()
                    ->forOrganization($organizationId)
                    ->unsettledDelivered()
                    ->whereKey((int) $serviceOrderId)
                    ->first();

                if (! $order) {
                    $validator->errors()->add(
                        'service_order_id',
                        'La reparación no está disponible para liquidación.'
                    );
                } elseif (
                    $order->customer_business_party_id !== null
                    && $this->input(
                        'customer_business_party_id'
                    ) !== null
                    && (int) $this->input(
                        'customer_business_party_id'
                    ) !== (int) $order->customer_business_party_id
                ) {
                    $validator->errors()->add(
                        'customer_business_party_id',
                        'El cliente seleccionado no coincide con la reparación.'
                    );
                }
            }

            foreach (
                (array) $this->input('payments', []) as $index => $payment
            ) {
                $method = CommercePaymentMethod::tryFrom(
                    (string) ($payment['method'] ?? '')
                );

                $tendered = $payment['tendered_amount'] ?? null;

                if ($method === CommercePaymentMethod::Cash) {
                    if (filled($tendered)) {
                        $appliedMinor = $this->moneyMinorValue(
                            $payment['amount'] ?? null
                        );
                        $tenderedMinor = $this->moneyMinorValue(
                            $tendered
                        );

                        if (
                            $appliedMinor !== null
                            && $tenderedMinor !== null
                            && $tenderedMinor < $appliedMinor
                        ) {
                            $validator->errors()->add(
                                "payments.{$index}.tendered_amount",
                                'El dinero entregado no puede ser menor que el importe aplicado.'
                            );
                        }
                    }
                } elseif ($method !== null && filled($tendered)) {
                    $validator->errors()->add(
                        "payments.{$index}.tendered_amount",
                        'Sólo el efectivo admite dinero entregado y vuelto.'
                    );
                }

                if (
                    $method?->requiresReference()
                    && blank($payment['reference'] ?? null)
                ) {
                    $validator->errors()->add(
                        "payments.{$index}.reference",
                        'El medio de pago seleccionado requiere una referencia.'
                    );
                }

                $isCard = in_array(
                    $method,
                    [
                        CommercePaymentMethod::DebitCard,
                        CommercePaymentMethod::CreditCard,
                    ],
                    true
                );
                $hasCardEvidence = collect([
                    $payment['card_brand'] ?? null,
                    $payment['card_network'] ?? null,
                    $payment['card_last4'] ?? null,
                    $payment['installments'] ?? null,
                ])->contains(fn (mixed $value): bool => filled($value));

                if (! $isCard && $hasCardEvidence) {
                    $validator->errors()->add(
                        "payments.{$index}.card_last4",
                        'Los datos de tarjeta sólo pueden registrarse en pagos con tarjeta.'
                    );
                }

                $hasProviderEvidence = collect([
                    $payment['processor'] ?? null,
                    $payment['external_operation_id'] ?? null,
                    $payment['authorization_code'] ?? null,
                    $payment['provider_status'] ?? null,
                ])->contains(fn (mixed $value): bool => filled($value));

                if (
                    $method === CommercePaymentMethod::Cash
                    && $hasProviderEvidence
                ) {
                    $validator->errors()->add(
                        "payments.{$index}.processor",
                        'El efectivo no admite evidencia de procesador u operación externa.'
                    );
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'currency_code.in' => 'La moneda de la venta debe ser ARS o USD.',
            'service_order_id.exists' => 'La reparación no pertenece a la organización activa o no fue entregada.',
            'customer_business_party_id.exists' => 'El cliente no pertenece a la organización activa.',
            'product_lines.*.catalog_product_id.exists' => 'El producto no existe o está inactivo.',
            'product_lines.*.source_location_id.exists' => 'La ubicación no pertenece a la organización activa o está inactiva.',
            'product_lines.*.quantity.regex' => 'La cantidad debe ser positiva y admitir hasta seis decimales.',
            'payments.min' => 'La venta requiere al menos un medio de pago.',
            'payments.*.amount.regex' => 'Cada pago debe ser positivo y admitir hasta dos decimales.',
            'payments.*.tendered_amount.regex' => 'El dinero entregado debe ser positivo y admitir hasta dos decimales.',
            'payments.*.financial_account_id.required' => 'Cada pago requiere una cuenta destino.',
            'payments.*.financial_account_id.exists' => 'La cuenta destino no pertenece a la organización, está inactiva o usa otra moneda.',
            'payments.*.card_last4.regex' => 'Los últimos 4 de la tarjeta deben contener exactamente cuatro dígitos.',
            'payments.*.pan.prohibited' => 'SRCM nunca admite almacenar el PAN completo de una tarjeta.',
            'payments.*.card_number.prohibited' => 'SRCM nunca admite almacenar el número completo de una tarjeta.',
            'payments.*.cvv.prohibited' => 'SRCM nunca admite almacenar CVV.',
            'payments.*.security_code.prohibited' => 'SRCM nunca admite almacenar códigos de seguridad de tarjeta.',
            'idempotency_key.regex' => 'La clave de seguridad comercial no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function moneyMinorValue(mixed $value): ?int
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (preg_match('/^\d{1,14}(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            return null;
        }

        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            ''
        );
        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function money(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
