<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleStatus;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleExchangeSelection;
use App\Models\CommercePostSaleExchangeSelectionLine;
use App\Models\CommercePostSaleResolution;
use App\Models\CommercePostSaleResolutionLine;
use App\Models\OrganizationProductPrice;
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

final class CommercePostSaleExchangeSelectionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly OrganizationProductPriceReader $priceReader,
        private readonly AuditRecorder $audit
    ) {
    }

    public function select(
        CommercePostSaleResolution $resolution,
        CommercePostSaleExchangeSelectionData $data,
        User $actor
    ): CommercePostSaleExchangeSelection {
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
                'No posee permiso para seleccionar el reemplazo de una posventa.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $resolution,
            $actor,
            $organizationId,
            $normalized
        ): CommercePostSaleExchangeSelection {
            $locked =
                CommercePostSaleResolution::query()
                    ->forOrganization($organizationId)
                    ->whereKey($resolution->id)
                    ->lockForUpdate()
                    ->first();

            if (! $locked) {
                throw new DomainException(
                    'La resolución de posventa no pertenece a la organización activa.'
                );
            }

            if (
                $locked->outcome
                    !== CommercePostSaleResolutionOutcome::Exchange
            ) {
                throw new DomainException(
                    'Sólo una resolución de cambio puede seleccionar mercadería de reemplazo.'
                );
            }

            $locked->loadMissing('request.sale');

            $sale = $locked->request?->sale;

            if (
                ! $sale
                || $sale->status
                    !== CommerceSaleStatus::Confirmed
                || $sale->currency_code
                    !== $locked->currency_code
            ) {
                throw new DomainException(
                    'El cambio requiere una venta original confirmada y consistente.'
                );
            }

            $recognizedAmountMinor =
                $this->recognizedAmountMinor(
                    $locked,
                    $organizationId
                );

            if ($recognizedAmountMinor <= 0) {
                throw new DomainException(
                    'El cambio requiere valor reconocido mayor que cero.'
                );
            }

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'commerce_post_sale_resolution_id' =>
                        (int) $locked->id,
                    'resolution_fingerprint' =>
                        (string) $locked->fingerprint,
                    'selected_by_user_id' =>
                        (int) $actor->id,
                    'notes' =>
                        $normalized['notes'],
                    'lines' =>
                        $normalized['lines'],
                ]);

            $existingByKey =
                CommercePostSaleExchangeSelection::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $normalized['idempotency_key']
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                if (
                    ! hash_equals(
                        (string) $existingByKey->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente del cambio ya fue utilizada con otro contenido.'
                    );
                }

                return $existingByKey->load([
                    'resolution.request.sale',
                    'selectedBy',
                    'lines.product',
                    'lines.price',
                ]);
            }

            $existingByResolution =
                CommercePostSaleExchangeSelection::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'commerce_post_sale_resolution_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByResolution) {
                throw new DomainException(
                    'La resolución ya posee otra selección de reemplazo.'
                );
            }

            $productIds = collect(
                $normalized['lines']
            )
                ->pluck('catalog_product_id')
                ->all();

            $products =
                CatalogProduct::query()
                    ->whereIn('id', $productIds)
                    ->where('active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw new DomainException(
                    'Un producto de reemplazo no existe o está inactivo.'
                );
            }

            $selectedAt =
                CarbonImmutable::now('UTC');

            $preparedLines = [];
            $replacementAmountMinor = 0;
            $sequence = 1;

            foreach ($normalized['lines'] as $lineData) {
                $product =
                    $products->get(
                        $lineData['catalog_product_id']
                    );

                if (! $product) {
                    throw new DomainException(
                        'Un producto de reemplazo no pudo resolverse.'
                    );
                }

                InventoryQuantity::assertFitsScale(
                    $lineData['quantity'],
                    (int) $product->quantity_scale,
                    'La cantidad del reemplazo'
                );

                $resolvedPrice =
                    $this->priceReader->priceAt(
                        $organizationId,
                        (int) $product->id,
                        $locked->currency_code,
                        $selectedAt
                    );

                $price =
                    OrganizationProductPrice::query()
                        ->forOrganization($organizationId)
                        ->whereKey($resolvedPrice->id)
                        ->lockForUpdate()
                        ->first();

                if (
                    ! $price
                    || (int) $price->catalog_product_id
                        !== (int) $product->id
                    || $price->currency_code
                        !== $locked->currency_code
                    || (int) $price->amount_minor <= 0
                    || ! $price->valid_from
                        ?->lessThanOrEqualTo($selectedAt)
                    || (
                        $price->valid_until !== null
                        && ! $price->valid_until
                            ->greaterThan($selectedAt)
                    )
                ) {
                    throw new DomainException(
                        'El precio autorizado del reemplazo cambió durante la selección.'
                    );
                }

                $lineAmountMinor =
                    $this->lineTotalMinor(
                        $lineData['quantity'],
                        (int) $price->amount_minor
                    );

                $replacementAmountMinor =
                    $this->sumMoney(
                        $replacementAmountMinor,
                        $lineAmountMinor
                    );

                $preparedLines[] = [
                    'sequence' => $sequence++,
                    'catalog_product_id' =>
                        (int) $product->id,
                    'organization_product_price_id' =>
                        (int) $price->id,
                    'quantity' =>
                        $lineData['quantity'],
                    'unit_price_minor' =>
                        (int) $price->amount_minor,
                    'line_amount_minor' =>
                        $lineAmountMinor,
                ];
            }

            if ($replacementAmountMinor <= 0) {
                throw new DomainException(
                    'El reemplazo seleccionado debe tener valor mayor que cero.'
                );
            }

            $selection =
                CommercePostSaleExchangeSelection::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_resolution_id' =>
                            $locked->id,
                        'currency_code' =>
                            $locked->currency_code,
                        'recognized_amount_minor' =>
                            $recognizedAmountMinor,
                        'selected_by_user_id' =>
                            $actor->id,
                        'selected_at' =>
                            $selectedAt,
                        'notes' =>
                            $normalized['notes'],
                        'idempotency_key' =>
                            $normalized['idempotency_key'],
                        'fingerprint' =>
                            $fingerprint,
                    ]);

            foreach ($preparedLines as $line) {
                CommercePostSaleExchangeSelectionLine::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_exchange_selection_id' =>
                            $selection->id,
                        ...$line,
                        'created_at' =>
                            $selectedAt,
                    ]);
            }

            $differenceAmountMinor =
                $replacementAmountMinor
                - $recognizedAmountMinor;

            $this->audit->record(
                $selection,
                'commerce_post_sale_exchange_replacement_selected',
                null,
                [
                    'commerce_post_sale_resolution_id' =>
                        (int) $locked->id,
                    'commerce_post_sale_request_id' =>
                        (int) $locked
                            ->commerce_post_sale_request_id,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'currency_code' =>
                        $locked->currency_code,
                    'recognized_amount_minor' =>
                        $recognizedAmountMinor,
                    'replacement_amount_minor' =>
                        $replacementAmountMinor,
                    'difference_amount_minor' =>
                        $differenceAmountMinor,
                    'line_count' =>
                        count($preparedLines),
                ]
            );

            return $selection->refresh()->load([
                'resolution.request.sale',
                'selectedBy',
                'lines.product',
                'lines.price',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *   notes:?string,
     *   idempotency_key:string,
     *   lines:list<array{
     *     catalog_product_id:int,
     *     quantity:string
     *   }>
     * }
     */
    private function normalize(
        CommercePostSaleExchangeSelectionData $data
    ): array {
        $idempotencyKey =
            Str::of($data->idempotencyKey)
                ->squish()
                ->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente de selección de cambio no es válida.'
            );
        }

        $notes = filled($data->notes)
            ? Str::of((string) $data->notes)
                ->squish()
                ->toString()
            : null;

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new DomainException(
                'La nota de selección de cambio supera la longitud admitida.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'El cambio requiere al menos un producto de reemplazo.'
            );
        }

        $lines = [];
        $seen = [];

        foreach ($data->lines as $line) {
            if (
                ! $line
                    instanceof CommercePostSaleExchangeSelectionLineData
                || $line->catalogProductId <= 0
                || isset($seen[$line->catalogProductId])
            ) {
                throw new DomainException(
                    'Una línea de reemplazo contiene una referencia inválida o duplicada.'
                );
            }

            $seen[$line->catalogProductId] = true;

            $lines[] = [
                'catalog_product_id' =>
                    $line->catalogProductId,
                'quantity' =>
                    InventoryQuantity::positive(
                        $line->quantity,
                        InventoryQuantity::SCALE,
                        'La cantidad del reemplazo'
                    ),
            ];
        }

        usort(
            $lines,
            static fn (
                array $left,
                array $right
            ): int =>
                $left['catalog_product_id']
                <=>
                $right['catalog_product_id']
        );

        return [
            'notes' => $notes,
            'idempotency_key' =>
                $idempotencyKey,
            'lines' => $lines,
        ];
    }

    private function recognizedAmountMinor(
        CommercePostSaleResolution $resolution,
        int $organizationId
    ): int {
        $lines =
            CommercePostSaleResolutionLine::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'commerce_post_sale_resolution_id',
                    $resolution->id
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        if ($lines->isEmpty()) {
            throw new DomainException(
                'La resolución no posee valor reconocido materializable.'
            );
        }

        $total = 0;

        foreach ($lines as $line) {
            $total =
                $this->sumMoney(
                    $total,
                    (int) $line
                        ->recognized_amount_minor
                );
        }

        return $total;
    }

    private function lineTotalMinor(
        string $quantity,
        int $unitPriceMinor
    ): int {
        if ($unitPriceMinor <= 0) {
            throw new DomainException(
                'El precio autorizado del reemplazo debe ser mayor que cero.'
            );
        }

        try {
            $total =
                BigDecimal::of($quantity)
                    ->multipliedBy($unitPriceMinor)
                    ->toScale(
                        0,
                        RoundingMode::Unnecessary
                    )
                    ->toBigInteger();

            if (
                ! $total->isPositive()
                || $total->isGreaterThan(
                    BigInteger::of(PHP_INT_MAX)
                )
            ) {
                throw new DomainException(
                    'El valor del reemplazo supera el importe admitido.'
                );
            }

            return (int) (string) $total;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad y el precio del reemplazo producen una fracción de centavo.',
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
            || $left > PHP_INT_MAX - $right
        ) {
            throw new DomainException(
                'El valor del reemplazo supera el importe admitido.'
            );
        }

        return $left + $right;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function fingerprint(array $source): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $source,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo construir la huella de la selección de cambio.',
                previous: $exception
            );
        }
    }
}
