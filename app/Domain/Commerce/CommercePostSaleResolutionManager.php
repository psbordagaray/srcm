<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleLineType;
use App\Enums\CommerceSaleStatus;
use App\Models\CommercePayment;
use App\Models\CommercePostSaleReceiptLine;
use App\Models\CommercePostSaleRequest;
use App\Models\CommercePostSaleRequestLine;
use App\Models\CommercePostSaleResolution;
use App\Models\CommercePostSaleResolutionLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class CommercePostSaleResolutionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function resolve(
        CommercePostSaleResolutionData $data,
        User $actor
    ): CommercePostSaleResolution {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canResolveCommercePostSale()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para resolver económicamente una posventa.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $normalized
        ): CommercePostSaleResolution {
            $request =
                CommercePostSaleRequest::query()
                    ->forOrganization($organizationId)
                    ->whereKey(
                        $data
                            ->commercePostSaleRequestId
                    )
                    ->lockForUpdate()
                    ->first();

            if (! $request) {
                throw new DomainException(
                    'La solicitud de posventa no pertenece a la organización activa.'
                );
            }

            $request->loadMissing('sale.payments');

            $sale = $request->sale;

            if (
                ! $sale
                || $sale->status
                    !== CommerceSaleStatus::Confirmed
            ) {
                throw new DomainException(
                    'La resolución requiere una venta original confirmada.'
                );
            }

            $existing =
                CommercePostSaleResolution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $normalized[
                            'idempotency_key'
                        ]
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    ! hash_equals(
                        (string) $existing->fingerprint,
                        $normalized['fingerprint']
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente de resolución ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load([
                    'request.sale',
                    'preferredOriginalPayment',
                    'lines.receiptLine.requestLine.saleLine.product',
                    'resolvedBy',
                ]);
            }

            if (
                $data->outcome
                    === CommercePostSaleResolutionOutcome::CustomerCredit
                && $sale->customer_business_party_id === null
            ) {
                throw new DomainException(
                    'Un saldo a favor requiere un cliente identificado en la venta original.'
                );
            }

            $preferredPayment = null;

            if (
                $normalized[
                    'preferred_original_payment_id'
                ] !== null
            ) {
                if (
                    $data->outcome
                        !== CommercePostSaleResolutionOutcome::Refund
                ) {
                    throw new DomainException(
                        'Sólo un reembolso puede señalar un medio original preferido.'
                    );
                }

                $preferredPayment =
                    CommercePayment::query()
                        ->forOrganization(
                            $organizationId
                        )
                        ->whereKey(
                            $normalized[
                                'preferred_original_payment_id'
                            ]
                        )
                        ->where(
                            'commerce_sale_id',
                            $sale->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (! $preferredPayment) {
                    throw new DomainException(
                        'El medio preferido no pertenece a los pagos originales de la venta.'
                    );
                }
            }

            $receiptLineIds = collect(
                $normalized['lines']
            )
                ->pluck(
                    'commerce_post_sale_receipt_line_id'
                )
                ->all();

            $receiptLines =
                CommercePostSaleReceiptLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->whereIn(
                        'id',
                        $receiptLineIds
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if (
                $receiptLines->count()
                !== count($receiptLineIds)
            ) {
                throw new DomainException(
                    'Una línea recibida no pertenece a la organización activa.'
                );
            }

            $receiptLines->load(
                'requestLine.saleLine.product',
                'receipt'
            );

            $receiptLinesById =
                $receiptLines->keyBy('id');

            $priorLines =
                CommercePostSaleResolutionLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->whereIn(
                        'commerce_post_sale_receipt_line_id',
                        $receiptLineIds
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(
                        'commerce_post_sale_receipt_line_id'
                    );

            $preparedLines = [];
            $recognizedTotal = 0;

            foreach (
                $normalized['lines']
                as $lineData
            ) {
                $receiptLine =
                    $receiptLinesById->get(
                        $lineData[
                            'commerce_post_sale_receipt_line_id'
                        ]
                    );

                $requestLine =
                    $receiptLine?->requestLine;

                $saleLine =
                    $requestLine?->saleLine;

                if (
                    ! $receiptLine
                    || ! $receiptLine->receipt
                    || (int) $receiptLine
                        ->receipt
                        ->commerce_post_sale_request_id
                        !== (int) $request->id
                    || ! $requestLine
                    || (int) $requestLine
                        ->commerce_post_sale_request_id
                        !== (int) $request->id
                    || ! $saleLine
                    || $saleLine->line_type
                        !== CommerceSaleLineType::Product
                    || $saleLine->catalog_product_id === null
                ) {
                    throw new DomainException(
                        'La línea a resolver no conserva recepción, solicitud y producto originales.'
                    );
                }

                $alreadyResolved =
                    BigDecimal::zero();

                foreach (
                    $priorLines->get(
                        $receiptLine->id,
                        collect()
                    ) as $prior
                ) {
                    $alreadyResolved =
                        $alreadyResolved->plus(
                            BigDecimal::of(
                                (string) $prior->quantity
                            )
                        );
                }

                $quantity =
                    BigDecimal::of(
                        $lineData['quantity']
                    );

                if (
                    $alreadyResolved
                        ->plus($quantity)
                        ->isGreaterThan(
                            BigDecimal::of(
                                (string) $receiptLine
                                    ->quantity
                            )
                        )
                ) {
                    throw new DomainException(
                        'La cantidad resuelta acumulada no puede superar la cantidad físicamente recibida.'
                    );
                }

                $baseline =
                    $this->lineTotalMinor(
                        $lineData['quantity'],
                        (int) $saleLine
                            ->unit_price_minor
                    );

                if (
                    $lineData[
                        'recognized_amount_minor'
                    ] > $baseline
                ) {
                    throw new DomainException(
                        'El valor reconocido no puede superar el valor original proporcional de la mercadería recibida.'
                    );
                }

                if (
                    $lineData[
                        'recognized_amount_minor'
                    ] < $baseline
                    && $lineData[
                        'adjustment_reason'
                    ] === null
                ) {
                    throw new DomainException(
                        'Toda reducción respecto del valor original requiere un motivo explícito.'
                    );
                }

                $recognizedTotal =
                    $this->sumMoney(
                        $recognizedTotal,
                        $lineData[
                            'recognized_amount_minor'
                        ]
                    );

                $preparedLines[] = [
                    ...$lineData,
                    'baseline_amount_minor' =>
                        $baseline,
                ];
            }

            if (
                $preferredPayment
                && $recognizedTotal
                    > $preferredPayment->amount_minor
            ) {
                throw new DomainException(
                    'El medio original preferido no alcanza para el valor reconocido de esta resolución.'
                );
            }

            $resolution =
                CommercePostSaleResolution::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_request_id' =>
                            $request->id,
                        'outcome' =>
                            $data->outcome,
                        'currency_code' =>
                            $sale->currency_code,
                        'preferred_original_payment_id' =>
                            $preferredPayment?->id,
                        'reason' =>
                            $normalized['reason'],
                        'notes' =>
                            $normalized['notes'],
                        'resolved_by_user_id' =>
                            $actor->id,
                        'resolved_at' =>
                            CarbonImmutable::now(),
                        'idempotency_key' =>
                            $normalized[
                                'idempotency_key'
                            ],
                        'fingerprint' =>
                            $normalized[
                                'fingerprint'
                            ],
                    ]);

            foreach (
                $preparedLines
                as $lineData
            ) {
                CommercePostSaleResolutionLine::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_resolution_id' =>
                            $resolution->id,
                        'commerce_post_sale_receipt_line_id' =>
                            $lineData[
                                'commerce_post_sale_receipt_line_id'
                            ],
                        'quantity' =>
                            $lineData['quantity'],
                        'baseline_amount_minor' =>
                            $lineData[
                                'baseline_amount_minor'
                            ],
                        'recognized_amount_minor' =>
                            $lineData[
                                'recognized_amount_minor'
                            ],
                        'adjustment_reason' =>
                            $lineData[
                                'adjustment_reason'
                            ],
                        'created_at' => now(),
                    ]);
            }

            $this->audit->record(
                $resolution,
                'commerce_post_sale_resolution_recorded',
                null,
                [
                    'commerce_post_sale_request_id' =>
                        (int) $request->id,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'outcome' =>
                        $data->outcome,
                    'currency_code' =>
                        $sale->currency_code,
                    'recognized_amount_minor' =>
                        $recognizedTotal,
                    'preferred_original_payment_id' =>
                        $preferredPayment?->id,
                    'line_count' =>
                        count($preparedLines),
                ]
            );

            return $resolution->refresh()->load([
                'request.sale',
                'preferredOriginalPayment',
                'lines.receiptLine.requestLine.saleLine.product',
                'resolvedBy',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *   reason:string,
     *   notes:?string,
     *   idempotency_key:string,
     *   preferred_original_payment_id:?int,
     *   lines:list<array{
     *     commerce_post_sale_receipt_line_id:int,
     *     quantity:string,
     *     recognized_amount_minor:int,
     *     adjustment_reason:?string
     *   }>,
     *   fingerprint:string
     * }
     */
    private function normalize(
        CommercePostSaleResolutionData $data
    ): array {
        $reason =
            Str::of($data->reason)
                ->squish()
                ->toString();

        if (
            mb_strlen($reason) < 10
            || mb_strlen($reason) > 1000
        ) {
            throw new DomainException(
                'La resolución requiere un motivo de 10 a 1000 caracteres.'
            );
        }

        $notes = filled($data->notes)
            ? trim((string) $data->notes)
            : null;

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new DomainException(
                'La nota de resolución supera la longitud admitida.'
            );
        }

        $idempotencyKey =
            trim($data->idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente de resolución no es válida.'
            );
        }

        if (
            $data->preferredOriginalPaymentId !== null
            && $data->preferredOriginalPaymentId <= 0
        ) {
            throw new DomainException(
                'El pago original preferido no es válido.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'La resolución requiere al menos una línea físicamente recibida.'
            );
        }

        $normalizedLines = [];
        $seen = [];

        foreach ($data->lines as $line) {
            if (
                ! $line
                    instanceof CommercePostSaleResolutionLineData
            ) {
                throw new DomainException(
                    'Las líneas de resolución son inválidas.'
                );
            }

            if (
                $line
                    ->commercePostSaleReceiptLineId
                    <= 0
                || isset(
                    $seen[
                        $line
                            ->commercePostSaleReceiptLineId
                    ]
                )
                || $line
                    ->recognizedAmountMinor
                    < 0
            ) {
                throw new DomainException(
                    'Una línea de resolución contiene referencias o importes inválidos.'
                );
            }

            $seen[
                $line
                    ->commercePostSaleReceiptLineId
            ] = true;

            $adjustmentReason =
                filled(
                    $line->adjustmentReason
                )
                    ? Str::of(
                        (string) $line
                            ->adjustmentReason
                    )
                        ->squish()
                        ->toString()
                    : null;

            if (
                $adjustmentReason !== null
                && (
                    mb_strlen(
                        $adjustmentReason
                    ) < 10
                    || mb_strlen(
                        $adjustmentReason
                    ) > 1000
                )
            ) {
                throw new DomainException(
                    'El motivo de ajuste debe tener entre 10 y 1000 caracteres.'
                );
            }

            $normalizedLines[] = [
                'commerce_post_sale_receipt_line_id' =>
                    $line
                        ->commercePostSaleReceiptLineId,
                'quantity' =>
                    $this->quantity(
                        $line->quantity
                    ),
                'recognized_amount_minor' =>
                    $line
                        ->recognizedAmountMinor,
                'adjustment_reason' =>
                    $adjustmentReason,
            ];
        }

        usort(
            $normalizedLines,
            static fn (
                array $left,
                array $right
            ): int =>
                $left[
                    'commerce_post_sale_receipt_line_id'
                ]
                <=>
                $right[
                    'commerce_post_sale_receipt_line_id'
                ]
        );

        $fingerprintSource = [
            'commerce_post_sale_request_id' =>
                $data
                    ->commercePostSaleRequestId,
            'outcome' =>
                $data->outcome->value,
            'preferred_original_payment_id' =>
                $data
                    ->preferredOriginalPaymentId,
            'reason' => $reason,
            'notes' => $notes,
            'lines' => $normalizedLines,
        ];

        try {
            $fingerprint = hash(
                'sha256',
                json_encode(
                    $fingerprintSource,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo construir la huella de la resolución de posventa.',
                previous: $exception
            );
        }

        return [
            'reason' => $reason,
            'notes' => $notes,
            'idempotency_key' =>
                $idempotencyKey,
            'preferred_original_payment_id' =>
                $data
                    ->preferredOriginalPaymentId,
            'lines' => $normalizedLines,
            'fingerprint' => $fingerprint,
        ];
    }

    private function quantity(
        string $value
    ): string {
        $value = str_replace(
            ',',
            '.',
            trim($value)
        );

        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d{1,6}))?$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'Una cantidad a resolver no es válida.'
            );
        }

        try {
            $quantity =
                BigDecimal::of($value)
                    ->toScale(
                        6,
                        RoundingMode::Unnecessary
                    );

            if (! $quantity->isPositive()) {
                throw new DomainException(
                    'La cantidad a resolver debe ser mayor que cero.'
                );
            }

            return (string) $quantity;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad a resolver supera la precisión admitida.',
                previous: $exception
            );
        }
    }

    private function lineTotalMinor(
        string $quantity,
        int $unitPriceMinor
    ): int {
        if ($unitPriceMinor < 0) {
            throw new DomainException(
                'El precio original de la línea no es válido.'
            );
        }

        try {
            $total =
                BigDecimal::of($quantity)
                    ->multipliedBy(
                        $unitPriceMinor
                    )
                    ->toScale(
                        0,
                        RoundingMode::Unnecessary
                    )
                    ->toBigInteger();

            if (
                $total->isGreaterThan(
                    BigInteger::of(
                        PHP_INT_MAX
                    )
                )
            ) {
                throw new DomainException(
                    'El valor de devolución supera el importe admitido.'
                );
            }

            return (int) (string) $total;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad recibida y el precio original producen una fracción de centavo.',
                previous: $exception
            );
        }
    }

    private function sumMoney(
        int $left,
        int $right
    ): int {
        if (
            $left < 0
            || $right < 0
            || $left
                > PHP_INT_MAX - $right
        ) {
            throw new DomainException(
                'El valor reconocido supera el importe admitido.'
            );
        }

        return $left + $right;
    }
}
