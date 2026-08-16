<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSaleLineType;
use App\Enums\CommerceSaleStatus;
use App\Models\CommercePostSaleRequest;
use App\Models\CommercePostSaleRequestLine;
use App\Models\CommerceSale;
use App\Models\CommerceSaleLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CommercePostSaleRequestManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function create(
        CommercePostSaleRequestData $data,
        User $actor
    ): CommercePostSaleRequest {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canRecordCommercePostSaleRequest() ?? false)) {
            throw new DomainException(
                'No posee permiso para registrar solicitudes de posventa.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $normalized
        ): CommercePostSaleRequest {
            $sale = CommerceSale::query()
                ->forOrganization($organizationId)
                ->whereKey($data->commerceSaleId)
                ->lockForUpdate()
                ->first();

            if (
                ! $sale
                || $sale->status !== CommerceSaleStatus::Confirmed
            ) {
                throw new DomainException(
                    'La venta original no está disponible como venta confirmada de la organización activa.'
                );
            }

            $existing = CommercePostSaleRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
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
                        'La clave idempotente de posventa ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load([
                    'lines.saleLine.product',
                    'requestedBy',
                ]);
            }

            $saleLineIds = collect($normalized['lines'])
                ->pluck('commerce_sale_line_id')
                ->all();

            $saleLines = CommerceSaleLine::query()
                ->where('organization_id', $organizationId)
                ->where('commerce_sale_id', $sale->id)
                ->whereIn('id', $saleLineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($saleLines->count() !== count($saleLineIds)) {
                throw new DomainException(
                    'Una línea solicitada no pertenece a la venta original.'
                );
            }

            foreach ($normalized['lines'] as $lineData) {
                $line = $saleLines->get(
                    $lineData['commerce_sale_line_id']
                );

                if (
                    ! $line
                    || $line->line_type !== CommerceSaleLineType::Product
                    || $line->catalog_product_id === null
                ) {
                    throw new DomainException(
                        'P8.1 sólo admite solicitudes sobre líneas de producto de la venta original.'
                    );
                }

                if (
                    BigDecimal::of($lineData['quantity'])
                        ->isGreaterThan(
                            BigDecimal::of((string) $line->quantity)
                        )
                ) {
                    throw new DomainException(
                        'La cantidad solicitada no puede superar la cantidad vendida en la línea original.'
                    );
                }
            }

            $request = CommercePostSaleRequest::query()->create([
                'organization_id' => $organizationId,
                'commerce_sale_id' => $sale->id,
                'intent' => $data->intent,
                'reason' => $normalized['reason'],
                'notes' => $normalized['notes'],
                'requested_by_user_id' => $actor->id,
                'requested_at' => CarbonImmutable::now(),
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            foreach ($normalized['lines'] as $lineData) {
                CommercePostSaleRequestLine::query()->create([
                    'organization_id' => $organizationId,
                    'commerce_post_sale_request_id' => $request->id,
                    'commerce_sale_line_id' =>
                        $lineData['commerce_sale_line_id'],
                    'quantity' => $lineData['quantity'],
                    'created_at' => now(),
                ]);
            }

            $this->audit->record(
                $request,
                'commerce_post_sale_request_recorded',
                null,
                [
                    'commerce_sale_id' => (int) $sale->id,
                    'sale_number' => (int) $sale->sale_number,
                    'intent' => $data->intent,
                    'reason' => $normalized['reason'],
                    'line_count' => count($normalized['lines']),
                ]
            );

            return $request->refresh()->load([
                'lines.saleLine.product',
                'requestedBy',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *   reason:string,
     *   notes:?string,
     *   idempotency_key:string,
     *   lines:list<array{
     *     commerce_sale_line_id:int,
     *     quantity:string
     *   }>,
     *   fingerprint:string
     * }
     */
    private function normalize(
        CommercePostSaleRequestData $data
    ): array {
        $reason = Str::of($data->reason)->squish()->toString();

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new DomainException(
                'La solicitud de posventa requiere un motivo de 10 a 500 caracteres.'
            );
        }

        $notes = filled($data->notes)
            ? trim((string) $data->notes)
            : null;

        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw new DomainException(
                'La nota de posventa supera la longitud admitida.'
            );
        }

        $idempotencyKey = trim($data->idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente de posventa no es válida.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'La solicitud de posventa requiere al menos una línea de producto.'
            );
        }

        $normalizedLines = [];
        $seen = [];

        foreach ($data->lines as $line) {
            if (! $line instanceof CommercePostSaleRequestLineData) {
                throw new DomainException(
                    'Las líneas de la solicitud de posventa son inválidas.'
                );
            }

            if (
                $line->commerceSaleLineId <= 0
                || isset($seen[$line->commerceSaleLineId])
            ) {
                throw new DomainException(
                    'Una línea de venta no puede repetirse en la misma solicitud de posventa.'
                );
            }

            $seen[$line->commerceSaleLineId] = true;

            $normalizedLines[] = [
                'commerce_sale_line_id' => $line->commerceSaleLineId,
                'quantity' => $this->quantity($line->quantity),
            ];
        }

        usort(
            $normalizedLines,
            static fn (array $left, array $right): int =>
                $left['commerce_sale_line_id']
                <=>
                $right['commerce_sale_line_id']
        );

        $fingerprintSource = [
            'commerce_sale_id' => $data->commerceSaleId,
            'intent' => $data->intent->value,
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
                'No se pudo construir la huella de la solicitud de posventa.',
                previous: $exception
            );
        }

        return [
            'reason' => $reason,
            'notes' => $notes,
            'idempotency_key' => $idempotencyKey,
            'lines' => $normalizedLines,
            'fingerprint' => $fingerprint,
        ];
    }

    private function quantity(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d{1,6}))?$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'Una cantidad de posventa no es válida.'
            );
        }

        $quantity = BigDecimal::of($value);

        if ($quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new DomainException(
                'La cantidad solicitada debe ser mayor que cero.'
            );
        }

        return (string) $quantity->toScale(
            6,
            RoundingMode::Unnecessary
        );
    }
}
