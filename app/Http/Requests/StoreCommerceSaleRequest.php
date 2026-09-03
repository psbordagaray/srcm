<?php

namespace App\Http\Requests;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionInput;
use App\Domain\Numerics\AuthoritativeNumericInput;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Domain\Numerics\HumanNumericInput;
use App\Domain\Numerics\NumericKind;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreCommerceSaleRequest extends FormRequest
{
    /** @var array<int, string|null> */
    private array $paymentAmountRawInputs = [];

    /** @var array<int, string|null> */
    private array $paymentTenderedAmountRawInputs = [];

    private ?string $receivableAmountRawInput = null;

    /** @var array<int, AuthoritativeNumericInput|null> */
    private array $paymentAmountAuthoritativeInputs = [];

    /** @var array<int, AuthoritativeNumericInput|null> */
    private array $paymentTenderedAmountAuthoritativeInputs = [];

    private bool $receivableAmountAuthoritativeResolved = false;

    private ?AuthoritativeNumericInput $receivableAmountAuthoritativeInput = null;

    public function authorize(): bool
    {
        return $this->user()?->can('record-commerce-sales')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->paymentAmountRawInputs = [];
        $this->paymentTenderedAmountRawInputs = [];
        $this->receivableAmountRawInput = null;
        $this->paymentAmountAuthoritativeInputs = [];
        $this->paymentTenderedAmountAuthoritativeInputs = [];
        $this->receivableAmountAuthoritativeResolved = false;
        $this->receivableAmountAuthoritativeInput = null;

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
                    $this->capturePaymentAmountRaw(
                        (string) ($payment['amount'] ?? '')
                    )
                ),
                'tendered_amount' => $this->optional(
                    $this->money(
                        $this->capturePaymentTenderedAmountRaw(
                            (string) ($payment['tendered_amount'] ?? '')
                        )
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
            'receivable_amount' => $this->optional(
                $this->money(
                    $this->captureReceivableAmountRaw(
                        (string) $this->input(
                            'receivable_amount',
                            ''
                        )
                    )
                )
            ),
            'receivable_due_on' => $this->optional(
                (string) $this->input(
                    'receivable_due_on',
                    ''
                )
            ),
            'receivable_installment_count' =>
                filled(
                    $this->input(
                        'receivable_installment_count'
                    )
                )
                    ? (int) $this->input(
                        'receivable_installment_count'
                    )
                    : null,
            'customer_credit_override_reason' =>
                $this->optional(
                    (string) $this->input(
                        'customer_credit_override_reason',
                        ''
                    )
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
                'array',
                'max:10',
            ],
            'receivable_amount' => $optionalPositiveMoney,
            'receivable_due_on' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'receivable_installment_count' => [
                'nullable',
                'integer',
                'min:1',
                'max:120',
            ],
            'customer_credit_override_reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'settlement_discrepancy_decision' => [
                'nullable',
                'string',
                'required_with:settlement_discrepancy_reason',
                Rule::in(
                    CommerceSettlementDiscrepancyDecisionInput::
                        AUTHORIZED_DECISION_VALUES
                ),
            ],
            'settlement_discrepancy_reason' => [
                'nullable',
                'string',
                'required_with:settlement_discrepancy_decision',
                'max:2048',
            ],
            'payments.*.method' => [
                'required',
                Rule::enum(CommercePaymentMethod::class),
            ],
            'payments.*.amount' => $positiveMoney,
            'payments.*.tendered_amount' => $optionalPositiveMoney,
            'payments.*.financial_account_id' => [
                'nullable',
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

            $receivableAmount = $this->validatedMoneyMinor(
                $validator,
                'receivable_amount',
                fn (): ?AuthoritativeNumericInput =>
                    $this->receivableAmountAuthoritativeInput()
            );
            $receivableDueOn = $this->input(
                'receivable_due_on'
            );
            $receivableInstallmentCount =
                $this->input(
                    'receivable_installment_count'
                );
            $payments = (array) $this->input(
                'payments',
                []
            );

            if (
                ! $validator->errors()->has(
                    'settlement_discrepancy_decision'
                )
                && ! $validator->errors()->has(
                    'settlement_discrepancy_reason'
                )
            ) {
                try {
                    $this->settlementDiscrepancyDecisionInput();
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add(
                        'settlement_discrepancy_reason',
                        $exception->getMessage()
                    );
                }
            }

            if (
                $payments === []
                && $receivableAmount === null
            ) {
                $validator->errors()->add(
                    'payments',
                    'La venta requiere un pago o un saldo pendiente autorizado.'
                );
            }

            if ($receivableAmount !== null) {
                if (blank(
                    $this->input(
                        'customer_business_party_id'
                    )
                )) {
                    $validator->errors()->add(
                        'receivable_amount',
                        'El saldo pendiente requiere un cliente vinculado.'
                    );
                }

                if (! $this->user()?->can(
                    'create-customer-receivables'
                )) {
                    $validator->errors()->add(
                        'receivable_amount',
                        'El rol no puede registrar una venta con saldo pendiente.'
                    );
                }
            }

            if (
                filled(
                    $this->input(
                        'customer_credit_override_reason'
                    )
                )
                && $receivableAmount === null
            ) {
                $validator->errors()->add(
                    'customer_credit_override_reason',
                    'No puede informarse una excepción de crédito sin saldo pendiente.'
                );
            }

            if (
                filled($receivableDueOn)
                && $receivableAmount === null
            ) {
                $validator->errors()->add(
                    'receivable_due_on',
                    'No puede informarse vencimiento sin saldo pendiente.'
                );
            }

            if (
                $receivableInstallmentCount !== null
                && $receivableAmount === null
            ) {
                $validator->errors()->add(
                    'receivable_installment_count',
                    'No pueden informarse cuotas propias sin saldo pendiente.'
                );
            }

            if (
                (int) ($receivableInstallmentCount ?? 1) > 1
                && blank($receivableDueOn)
            ) {
                $validator->errors()->add(
                    'receivable_due_on',
                    'Las cuotas propias requieren un primer vencimiento.'
                );
            }

            if (
                $receivableAmount !== null
                && (int) (
                    $receivableInstallmentCount ?? 1
                ) > 1
                && $receivableAmount
                    < (int) $receivableInstallmentCount
            ) {
                $validator->errors()->add(
                    'receivable_installment_count',
                    'El importe pendiente es demasiado pequeño para esa cantidad de cuotas.'
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
                $appliedMinor = $this->validatedMoneyMinor(
                    $validator,
                    "payments.{$index}.amount",
                    fn (): ?AuthoritativeNumericInput =>
                        $this->paymentAmountAuthoritativeInput((int) $index)
                );
                $tenderedMinor = $this->validatedMoneyMinor(
                    $validator,
                    "payments.{$index}.tendered_amount",
                    fn (): ?AuthoritativeNumericInput =>
                        $this->paymentTenderedAmountAuthoritativeInput(
                            (int) $index
                        )
                );

                if (
                    $method
                        === CommercePaymentMethod::AccountCredit
                ) {
                    if (
                        blank(
                            $this->input(
                                'customer_business_party_id'
                            )
                        )
                    ) {
                        $validator->errors()->add(
                            "payments.{$index}.method",
                            'El crédito en cuenta requiere un cliente vinculado.'
                        );
                    }

                    if (
                        filled(
                            $payment[
                                'financial_account_id'
                            ] ?? null
                        )
                    ) {
                        $validator->errors()->add(
                            "payments.{$index}.financial_account_id",
                            'El crédito en cuenta no utiliza cuenta financiera.'
                        );
                    }

                    if (
                        filled(
                            $payment['reference']
                                ?? null
                        )
                    ) {
                        $validator->errors()->add(
                            "payments.{$index}.reference",
                            'La referencia del crédito en cuenta es generada por SRCM.'
                        );
                    }

                    if (
                        collect([
                            $payment[
                                'tendered_amount'
                            ] ?? null,
                            $payment[
                                'card_brand'
                            ] ?? null,
                            $payment[
                                'card_network'
                            ] ?? null,
                            $payment[
                                'card_last4'
                            ] ?? null,
                            $payment[
                                'installments'
                            ] ?? null,
                            $payment[
                                'processor'
                            ] ?? null,
                            $payment[
                                'external_operation_id'
                            ] ?? null,
                            $payment[
                                'authorization_code'
                            ] ?? null,
                            $payment[
                                'provider_status'
                            ] ?? null,
                        ])->contains(
                            fn (
                                mixed $value
                            ): bool =>
                                filled($value)
                        )
                    ) {
                        $validator->errors()->add(
                            "payments.{$index}.method",
                            'El crédito en cuenta no admite efectivo, tarjeta ni evidencia de proveedor.'
                        );
                    }
                } elseif (
                    $method !== null
                    && blank(
                        $payment[
                            'financial_account_id'
                        ] ?? null
                    )
                ) {
                    $validator->errors()->add(
                        "payments.{$index}.financial_account_id",
                        'El medio de pago requiere una cuenta financiera activa.'
                    );
                }

                if ($method === CommercePaymentMethod::Cash) {
                    if (filled($tendered)) {
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
            'receivable_amount.regex' => 'El saldo pendiente debe ser positivo y admitir hasta dos decimales.',
            'receivable_due_on.date_format' => 'El vencimiento debe expresarse como fecha válida.',
            'receivable_due_on.after_or_equal' => 'El vencimiento no puede ser anterior a hoy.',
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

    public function settlementDiscrepancyDecisionInput():
        ?CommerceSettlementDiscrepancyDecisionInput
    {
        $decision = $this->input(
            'settlement_discrepancy_decision'
        );
        $reason = $this->input(
            'settlement_discrepancy_reason'
        );

        if ($decision === null && $reason === null) {
            return null;
        }

        if (
            $decision !== 'KEEP_REFERENCE'
            || ! is_string($reason)
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement decision input currently supports KEEP_REFERENCE only with an explicit reason.'
            );
        }

        return CommerceSettlementDiscrepancyDecisionInput::
            keepReference($reason);
    }

    public function paymentAmountAuthoritativeInput(
        int $index
    ): ?AuthoritativeNumericInput {
        if (array_key_exists(
            $index,
            $this->paymentAmountAuthoritativeInputs
        )) {
            return $this->paymentAmountAuthoritativeInputs[$index];
        }

        $resolved = $this->authoritativeMoney(
            $this->paymentAmountRawInputs[$index] ?? null
        );
        $this->paymentAmountAuthoritativeInputs[$index] = $resolved;

        return $resolved;
    }

    public function paymentTenderedAmountAuthoritativeInput(
        int $index
    ): ?AuthoritativeNumericInput {
        if (array_key_exists(
            $index,
            $this->paymentTenderedAmountAuthoritativeInputs
        )) {
            return $this->paymentTenderedAmountAuthoritativeInputs[$index];
        }

        $resolved = $this->authoritativeMoney(
            $this->paymentTenderedAmountRawInputs[$index] ?? null
        );
        $this->paymentTenderedAmountAuthoritativeInputs[$index] = $resolved;

        return $resolved;
    }

    public function receivableAmountAuthoritativeInput():
        ?AuthoritativeNumericInput
    {
        if ($this->receivableAmountAuthoritativeResolved) {
            return $this->receivableAmountAuthoritativeInput;
        }

        $resolved = $this->authoritativeMoney(
            $this->receivableAmountRawInput
        );
        $this->receivableAmountAuthoritativeInput = $resolved;
        $this->receivableAmountAuthoritativeResolved = true;

        return $resolved;
    }

    private function capturePaymentAmountRaw(string $value): string
    {
        $raw = trim($value);
        $this->paymentAmountRawInputs[] = $raw === '' ? null : $raw;

        return $value;
    }

    private function capturePaymentTenderedAmountRaw(
        string $value
    ): string {
        $raw = trim($value);
        $this->paymentTenderedAmountRawInputs[] =
            $raw === '' ? null : $raw;

        return $value;
    }

    private function captureReceivableAmountRaw(string $value): string
    {
        $raw = trim($value);
        $this->receivableAmountRawInput = $raw === '' ? null : $raw;

        return $value;
    }

    private function authoritativeMoney(
        ?string $raw
    ): ?AuthoritativeNumericInput {
        if ($raw === null) {
            return null;
        }

        $separator = str_contains($raw, ',')
            ? HumanNumericInput::SEPARATOR_COMMA
            : (
                str_contains($raw, '.')
                    ? HumanNumericInput::SEPARATOR_DOT
                    : HumanNumericInput::SEPARATOR_NONE
            );

        return AuthoritativeNumericInput::humanParsed(
            HumanNumericInput::parse(
                raw: $raw,
                kind: NumericKind::Money,
                decimalSeparator: $separator,
                maxScale: 2,
            ),
            2,
        );
    }

    /**
     * @param callable(): ?AuthoritativeNumericInput $resolver
     */
    private function validatedMoneyMinor(
        Validator $validator,
        string $attribute,
        callable $resolver,
    ): ?int {
        try {
            $authoritative = $resolver();
        } catch (InvalidArgumentException) {
            $validator->errors()->add(
                $attribute,
                'El importe debe usar una representación decimal canónica y no ambigua.'
            );

            return null;
        }

        if ($authoritative === null) {
            return null;
        }

        if (
            $authoritative->canonical->isZero()
            || $authoritative->canonical->isNegative()
        ) {
            $validator->errors()->add(
                $attribute,
                'El importe debe ser positivo.'
            );

            return null;
        }

        return ExactDecimalLegacyAdapter::toMinorUnit(
            $authoritative->canonical,
            2,
        );
    }

    private function money(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }
}
