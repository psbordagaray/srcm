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
                || filled($line['unit_price'] ?? null)
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
                'unit_price' => $this->money(
                    (string) ($line['unit_price'] ?? '')
                ),
            ])
            ->values()
            ->all();

        $payments = collect((array) $this->input('payments', []))
            ->filter(fn (mixed $payment): bool => is_array($payment)
            )
            ->filter(fn (array $payment): bool => filled($payment['amount'] ?? null)
                || filled($payment['reference'] ?? null)
                || filled($payment['notes'] ?? null)
                || filled($payment['paid_at'] ?? null)
            )
            ->map(fn (array $payment): array => [
                'method' => strtolower(trim(
                    (string) ($payment['method'] ?? '')
                )),
                'amount' => $this->money(
                    (string) ($payment['amount'] ?? '')
                ),
                'reference' => $this->optional(
                    (string) ($payment['reference'] ?? '')
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
        $nonNegativeMoney = [
            'required',
            'string',
            'max:18',
            'regex:/^\d{1,14}(?:[.,]\d{1,2})?$/',
        ];

        return [
            'currency_code' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
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
            'sold_at' => ['nullable', 'date'],
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
            'product_lines.*.unit_price' => $nonNegativeMoney,
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
            'payments.*.reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payments.*.notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'payments.*.paid_at' => ['nullable', 'date'],
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

                if (
                    $method?->requiresReference()
                    && blank($payment['reference'] ?? null)
                ) {
                    $validator->errors()->add(
                        "payments.{$index}.reference",
                        'El medio de pago seleccionado requiere una referencia.'
                    );
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'service_order_id.exists' => 'La reparación no pertenece a la organización activa o no fue entregada.',
            'customer_business_party_id.exists' => 'El cliente no pertenece a la organización activa.',
            'product_lines.*.catalog_product_id.exists' => 'El producto no existe o está inactivo.',
            'product_lines.*.source_location_id.exists' => 'La ubicación no pertenece a la organización activa o está inactiva.',
            'product_lines.*.quantity.regex' => 'La cantidad debe ser positiva y admitir hasta seis decimales.',
            'product_lines.*.unit_price.regex' => 'El precio no puede ser negativo y admite hasta dos decimales.',
            'payments.min' => 'La venta requiere al menos un medio de pago.',
            'payments.*.amount.regex' => 'Cada pago debe ser positivo y admitir hasta dos decimales.',
            'idempotency_key.regex' => 'La clave de seguridad comercial no es válida.',
        ];
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function money(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
