<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\CommercePostSaleReceipt;
use App\Models\CommercePostSaleReceiptLine;
use App\Models\CommercePostSaleRequest;
use App\Models\CommercePostSaleRequestLine;
use App\Models\InventoryLocation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CommercePostSaleReceiptManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly InventoryMovementCreator $movementCreator,
        private readonly InventoryMovementConfirmer $movementConfirmer,
        private readonly AuditRecorder $audit
    ) {
    }

    public function receive(
        CommercePostSaleReceiptData $data,
        User $actor
    ): CommercePostSaleReceipt {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canReceiveCommercePostSaleReturn()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para confirmar la recepción física de una posventa.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $normalized
        ): CommercePostSaleReceipt {
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

            $existing =
                CommercePostSaleReceipt::query()
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
                        'La clave idempotente de recepción ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load([
                    'request.sale',
                    'inventoryMovement.lines',
                    'lines.requestLine.saleLine.product',
                    'lines.destinationLocation',
                    'receivedBy',
                ]);
            }

            $requestLineIds = collect(
                $normalized['lines']
            )
                ->pluck(
                    'commerce_post_sale_request_line_id'
                )
                ->all();

            $requestLines =
                CommercePostSaleRequestLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_post_sale_request_id',
                        $request->id
                    )
                    ->whereIn(
                        'id',
                        $requestLineIds
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if (
                $requestLines->count()
                !== count($requestLineIds)
            ) {
                throw new DomainException(
                    'Una línea recibida no pertenece a la solicitud de posventa.'
                );
            }

            $requestLines->load(
                'saleLine.product'
            );

            $priorReceiptLines =
                CommercePostSaleReceiptLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->whereIn(
                        'commerce_post_sale_request_line_id',
                        $requestLineIds
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(
                        'commerce_post_sale_request_line_id'
                    );

            $requestLinesById =
                $requestLines->keyBy('id');

            foreach (
                $normalized['lines']
                as $lineData
            ) {
                $requestLine =
                    $requestLinesById->get(
                        $lineData[
                            'commerce_post_sale_request_line_id'
                        ]
                    );

                if (
                    ! $requestLine
                    || ! $requestLine->saleLine
                    || ! $requestLine
                        ->saleLine
                        ->product
                ) {
                    throw new DomainException(
                        'La línea de posventa no conserva un producto vendible trazable.'
                    );
                }

                $alreadyReceived =
                    BigDecimal::zero();

                foreach (
                    $priorReceiptLines->get(
                        $requestLine->id,
                        collect()
                    ) as $prior
                ) {
                    $alreadyReceived =
                        $alreadyReceived->plus(
                            BigDecimal::of(
                                (string) $prior
                                    ->quantity
                            )
                        );
                }

                $afterReceipt =
                    $alreadyReceived->plus(
                        BigDecimal::of(
                            $lineData['quantity']
                        )
                    );

                if (
                    $afterReceipt->isGreaterThan(
                        BigDecimal::of(
                            (string) $requestLine
                                ->quantity
                        )
                    )
                ) {
                    throw new DomainException(
                        'La recepción física acumulada no puede superar la cantidad solicitada para esa línea.'
                    );
                }
            }

            $destinationIds = collect(
                $normalized['lines']
            )
                ->pluck(
                    'destination_location_id'
                )
                ->unique()
                ->values();

            $destinations =
                InventoryLocation::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->whereIn(
                        'id',
                        $destinationIds
                    )
                    ->where('active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            if (
                $destinations->count()
                !== $destinationIds->count()
            ) {
                throw new DomainException(
                    'Una ubicación de recepción no está activa o no pertenece a la organización.'
                );
            }

            $receiptPublicId =
                (string) Str::uuid();

            $effectiveAt =
                CarbonImmutable::now();

            $inventoryLines = [];

            foreach (
                $normalized['lines']
                as $lineData
            ) {
                $requestLine =
                    $requestLinesById->get(
                        $lineData[
                            'commerce_post_sale_request_line_id'
                        ]
                    );

                $product =
                    $requestLine
                        ->saleLine
                        ->product;

                $inventoryLines[] =
                    new InventoryMovementLineData(
                        catalogProductId:
                            (int) $product->id,
                        condition:
                            $lineData['condition'],
                        enteredQuantity:
                            $lineData['quantity'],
                        enteredUnitCode:
                            $product
                                ->base_unit_code,
                        conversionFactor: '1',
                        destinationLocationId:
                            $lineData[
                                'destination_location_id'
                            ],
                        notes:
                            $lineData['notes']
                    );
            }

            $movement =
                $this->movementCreator->create(
                    new InventoryMovementDraftData(
                        type:
                            InventoryMovementType::CustomerReturn,
                        effectiveAt:
                            $effectiveAt,
                        reason:
                            'Recepción física de posventa '
                            .$request->public_id,
                        idempotencyKey:
                            'p8:return:'
                            .hash(
                                'sha256',
                                $organizationId
                                .'|'
                                .$normalized[
                                    'idempotency_key'
                                ]
                            ),
                        lines:
                            $inventoryLines,
                        sourceType:
                            'commerce_post_sale_receipt',
                        sourceId:
                            $receiptPublicId,
                        sourceReference:
                            'post-sale-request:'
                            .$request->public_id,
                        metadata: [
                            'commerce_post_sale_request_public_id' =>
                                $request->public_id,
                            'commerce_sale_id' =>
                                (int) $request
                                    ->commerce_sale_id,
                            'post_sale_intent' =>
                                $request
                                    ->intent
                                    ->value,
                        ]
                    ),
                    $actor
                );

            $movement =
                $this->movementConfirmer->confirm(
                    $movement,
                    $actor
                );

            if (
                $movement->type
                    !== InventoryMovementType::CustomerReturn
                || $movement->status
                    !== InventoryMovementStatus::Confirmed
                || $movement->confirmed_at === null
            ) {
                throw new DomainException(
                    'La recepción física no logró confirmar un CustomerReturn válido.'
                );
            }

            $receipt =
                CommercePostSaleReceipt::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'public_id' =>
                            $receiptPublicId,
                        'commerce_post_sale_request_id' =>
                            $request->id,
                        'inventory_movement_id' =>
                            $movement->id,
                        'received_by_user_id' =>
                            $actor->id,
                        'received_at' =>
                            $movement->confirmed_at,
                        'notes' =>
                            $normalized['notes'],
                        'idempotency_key' =>
                            $normalized[
                                'idempotency_key'
                            ],
                        'fingerprint' =>
                            $normalized['fingerprint'],
                    ]);

            $movementLines =
                $movement
                    ->lines
                    ->values();

            if (
                $movementLines->count()
                !== count(
                    $normalized['lines']
                )
            ) {
                throw new DomainException(
                    'El CustomerReturn confirmado no conserva las líneas esperadas.'
                );
            }

            foreach (
                $normalized['lines']
                as $index => $lineData
            ) {
                $inventoryLine =
                    $movementLines->get($index);

                CommercePostSaleReceiptLine::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_receipt_id' =>
                            $receipt->id,
                        'commerce_post_sale_request_line_id' =>
                            $lineData[
                                'commerce_post_sale_request_line_id'
                            ],
                        'inventory_movement_line_id' =>
                            $inventoryLine->id,
                        'quantity' =>
                            $lineData['quantity'],
                        'condition' =>
                            $lineData['condition'],
                        'destination_location_id' =>
                            $lineData[
                                'destination_location_id'
                            ],
                        'notes' =>
                            $lineData['notes'],
                        'created_at' => now(),
                    ]);
            }

            $this->audit->record(
                $receipt,
                'commerce_post_sale_receipt_confirmed',
                null,
                [
                    'commerce_post_sale_request_id' =>
                        (int) $request->id,
                    'commerce_sale_id' =>
                        (int) $request
                            ->commerce_sale_id,
                    'inventory_movement_id' =>
                        (int) $movement->id,
                    'line_count' =>
                        count(
                            $normalized['lines']
                        ),
                ]
            );

            return $receipt->refresh()->load([
                'request.sale',
                'inventoryMovement.lines',
                'lines.requestLine.saleLine.product',
                'lines.destinationLocation',
                'receivedBy',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *   notes:?string,
     *   idempotency_key:string,
     *   lines:list<array{
     *     commerce_post_sale_request_line_id:int,
     *     quantity:string,
     *     condition:\App\Enums\InventoryCondition,
     *     destination_location_id:int,
     *     notes:?string
     *   }>,
     *   fingerprint:string
     * }
     */
    private function normalize(
        CommercePostSaleReceiptData $data
    ): array {
        $notes = filled($data->notes)
            ? trim((string) $data->notes)
            : null;

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new DomainException(
                'La nota de recepción física supera la longitud admitida.'
            );
        }

        $idempotencyKey =
            trim($data->idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente de recepción física no es válida.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'La recepción física requiere al menos una línea.'
            );
        }

        $normalizedLines = [];
        $seen = [];

        foreach ($data->lines as $line) {
            if (
                ! $line
                    instanceof CommercePostSaleReceiptLineData
            ) {
                throw new DomainException(
                    'Las líneas de recepción física son inválidas.'
                );
            }

            if (
                $line
                    ->commercePostSaleRequestLineId
                    <= 0
                || isset(
                    $seen[
                        $line
                            ->commercePostSaleRequestLineId
                    ]
                )
                || $line
                    ->destinationLocationId
                    <= 0
            ) {
                throw new DomainException(
                    'Una línea de recepción física contiene referencias inválidas o repetidas.'
                );
            }

            $seen[
                $line
                    ->commercePostSaleRequestLineId
            ] = true;

            $lineNotes = filled(
                $line->notes
            )
                ? trim(
                    (string) $line->notes
                )
                : null;

            if (
                $lineNotes !== null
                && mb_strlen($lineNotes) > 1000
            ) {
                throw new DomainException(
                    'La nota de una línea recibida supera la longitud admitida.'
                );
            }

            $normalizedLines[] = [
                'commerce_post_sale_request_line_id' =>
                    $line
                        ->commercePostSaleRequestLineId,
                'quantity' =>
                    $this->quantity(
                        $line->quantity
                    ),
                'condition' =>
                    $line->condition,
                'destination_location_id' =>
                    $line
                        ->destinationLocationId,
                'notes' => $lineNotes,
            ];
        }

        usort(
            $normalizedLines,
            static fn (
                array $left,
                array $right
            ): int =>
                $left[
                    'commerce_post_sale_request_line_id'
                ]
                <=>
                $right[
                    'commerce_post_sale_request_line_id'
                ]
        );

        $fingerprintSource = [
            'commerce_post_sale_request_id' =>
                $data
                    ->commercePostSaleRequestId,
            'notes' => $notes,
            'lines' => array_map(
                static fn (
                    array $line
                ): array => [
                    'commerce_post_sale_request_line_id' =>
                        $line[
                            'commerce_post_sale_request_line_id'
                        ],
                    'quantity' =>
                        $line['quantity'],
                    'condition' =>
                        $line[
                            'condition'
                        ]->value,
                    'destination_location_id' =>
                        $line[
                            'destination_location_id'
                        ],
                    'notes' =>
                        $line['notes'],
                ],
                $normalizedLines
            ),
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
                'No se pudo construir la huella de la recepción física.',
                previous: $exception
            );
        }

        return [
            'notes' => $notes,
            'idempotency_key' =>
                $idempotencyKey,
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
                'Una cantidad recibida no es válida.'
            );
        }

        $quantity =
            BigDecimal::of($value);

        if (
            $quantity->isLessThanOrEqualTo(
                BigDecimal::zero()
            )
        ) {
            throw new DomainException(
                'La cantidad recibida debe ser mayor que cero.'
            );
        }

        return (string) $quantity->toScale(
            6,
            RoundingMode::Unnecessary
        );
    }
}
