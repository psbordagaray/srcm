<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSaleLineType;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalPointOfSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalAdjustmentManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function record(
        FiscalAdjustmentData $data,
        User $actor
    ): FiscalDocument {
        $organizationId = $this->organizationId($actor);

        if (! in_array(
            $data->documentType,
            [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote],
            true
        )) {
            throw new DomainException(
                'El ajuste fiscal debe ser una nota de crédito o una nota de débito.'
            );
        }

        if (blank($data->idempotencyKey)) {
            throw new DomainException(
                'La clave de idempotencia fiscal es obligatoria.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $normalized
        ): FiscalDocument {
            $point = FiscalPointOfSale::query()
                ->forOrganization($organizationId)
                ->with('profile')
                ->lockForUpdate()
                ->find($data->fiscalPointOfSaleId);

            if (! $point || ! $point->active || ! $point->profile) {
                throw new DomainException(
                    'El punto de venta fiscal activo y su perfil son obligatorios.'
                );
            }

            $issuer = [
                'legal_name' => $point->profile->legal_name,
                'tax_id' => $point->profile->tax_id,
                'vat_condition_code' => $point->profile->vat_condition_code,
                'address_line' => $point->profile->address_line,
                'city' => $point->profile->city,
                'province_code' => $point->profile->province_code,
                'postal_code' => $point->profile->postal_code,
                'country_code' => $point->profile->country_code,
                'point_public_id' => $point->public_id,
                'point_number' => $point->point_number,
                'environment' => $point->environment->value,
                'integration_mode' => $point->integration_mode->value,
            ];

            $fingerprintPayload = [
                'point' => $point->public_id,
                'type' => $data->documentType->value,
                'issuer' => $issuer,
                'recipient' => $normalized['recipient_snapshot'],
                'currency' => $normalized['currency_code'],
                'service' => $data->serviceSubtotalMinor,
                'product' => $data->productSubtotalMinor,
                'total' => $data->totalMinor,
                'lines' => $normalized['lines'],
            ];

            $fingerprint = hash(
                'sha256',
                json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)
            );

            $existing = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->fingerprint !== $fingerprint) {
                    throw new DomainException(
                        'La clave de idempotencia fiscal ya fue usada con otro documento.'
                    );
                }

                if (! in_array(
                    $existing->document_type,
                    [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote],
                    true
                )) {
                    throw new DomainException(
                        'La clave de idempotencia no corresponde a un ajuste fiscal.'
                    );
                }

                return $existing->load('lines', 'profile', 'pointOfSale');
            }

            $document = FiscalDocument::query()->create([
                'organization_id' => $organizationId,
                'fiscal_organization_profile_id' => $point->profile->id,
                'fiscal_point_of_sale_id' => $point->id,
                'commerce_sale_id' => null,
                'document_type' => $data->documentType,
                'issuer_snapshot' => $issuer,
                'recipient_snapshot' => $normalized['recipient_snapshot'],
                'currency_code' => $normalized['currency_code'],
                'service_subtotal_minor' => $data->serviceSubtotalMinor,
                'product_subtotal_minor' => $data->productSubtotalMinor,
                'total_minor' => $data->totalMinor,
                'documented_at' => CarbonImmutable::now(),
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $data->idempotencyKey,
                'fingerprint' => $fingerprint,
            ]);

            foreach ($normalized['lines'] as $line) {
                FiscalDocumentLine::query()->create([
                    'organization_id' => $organizationId,
                    'fiscal_document_id' => $document->id,
                    'commerce_sale_line_id' => null,
                    'position' => $line['position'],
                    'line_type' => $line['line_type'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            $this->audit->record(
                $document,
                'fiscal_adjustment_recorded',
                null,
                [
                    'document_type' => $data->documentType->value,
                    'total_minor' => $data->totalMinor,
                    'line_count' => count($normalized['lines']),
                    'commerce_sale_id' => null,
                ]
            );

            return $document
                ->refresh()
                ->load('lines', 'profile', 'pointOfSale');
        }, 3);
    }

    /**
     * @return array{
     *   recipient_snapshot:array<string,mixed>,
     *   currency_code:string,
     *   lines:list<array{
     *     position:int,
     *     line_type:string,
     *     description:string,
     *     quantity:string,
     *     unit_price_minor:int,
     *     line_total_minor:int
     *   }>
     * }
     */
    private function normalize(FiscalAdjustmentData $data): array
    {
        if (
            $data->serviceSubtotalMinor < 0
            || $data->productSubtotalMinor < 0
            || $data->totalMinor <= 0
            || $data->serviceSubtotalMinor + $data->productSubtotalMinor !== $data->totalMinor
        ) {
            throw new DomainException(
                'Los importes del ajuste deben ser positivos y sus subtotales deben sumar exactamente el total.'
            );
        }

        $currencyCode = strtoupper(trim($data->currencyCode));

        if (! preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new DomainException(
                'La moneda del ajuste debe declararse explícitamente con tres letras.'
            );
        }

        if ($data->recipientSnapshot === []) {
            throw new DomainException(
                'El receptor del ajuste debe declararse explícitamente.'
            );
        }

        $recipientSnapshot = $this->normalizeSnapshot(
            $data->recipientSnapshot
        );

        if (
            ! array_key_exists('name', $recipientSnapshot)
            || blank((string) $recipientSnapshot['name'])
        ) {
            throw new DomainException(
                'El snapshot explícito del receptor debe incluir name.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'El ajuste fiscal requiere al menos una línea explícita.'
            );
        }

        $normalizedLines = [];
        $positions = [];
        $serviceSum = 0;
        $productSum = 0;

        foreach ($data->lines as $line) {
            if (! $line instanceof FiscalAdjustmentLineData) {
                throw new DomainException(
                    'Todas las líneas del ajuste deben ser FiscalAdjustmentLineData.'
                );
            }

            if ($line->position <= 0 || isset($positions[$line->position])) {
                throw new DomainException(
                    'Las posiciones del ajuste deben ser positivas y únicas.'
                );
            }

            $positions[$line->position] = true;

            $description = trim($line->description);

            if ($description === '' || mb_strlen($description) > 255) {
                throw new DomainException(
                    'Cada línea requiere una descripción de hasta 255 caracteres.'
                );
            }

            $quantity = trim($line->quantity);

            if (
                ! preg_match('/^\d+(?:\.\d{1,6})?$/', $quantity)
                || preg_match('/^0+(?:\.0+)?$/', $quantity)
            ) {
                throw new DomainException(
                    'La cantidad debe ser positiva y tener hasta seis decimales.'
                );
            }

            if ($line->unitPriceMinor < 0 || $line->lineTotalMinor <= 0) {
                throw new DomainException(
                    'Los importes de cada línea deben ser no negativos y su total debe ser positivo.'
                );
            }

            if ($line->lineType === CommerceSaleLineType::Service) {
                $serviceSum += $line->lineTotalMinor;
            } elseif ($line->lineType === CommerceSaleLineType::Product) {
                $productSum += $line->lineTotalMinor;
            } else {
                throw new DomainException(
                    'Tipo de línea fiscal no soportado.'
                );
            }

            $normalizedLines[] = [
                'position' => $line->position,
                'line_type' => $line->lineType->value,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price_minor' => $line->unitPriceMinor,
                'line_total_minor' => $line->lineTotalMinor,
            ];
        }

        $actualPositions = array_keys($positions);
        sort($actualPositions);

        if (
            $actualPositions !== range(1, count($actualPositions))
        ) {
            throw new DomainException(
                'Las posiciones del ajuste deben ser contiguas desde 1.'
            );
        }

        usort(
            $normalizedLines,
            static fn (array $left, array $right): int =>
                $left['position'] <=> $right['position']
        );

        if (
            $serviceSum !== $data->serviceSubtotalMinor
            || $productSum !== $data->productSubtotalMinor
            || $serviceSum + $productSum !== $data->totalMinor
        ) {
            throw new DomainException(
                'La suma explícita de líneas debe coincidir con los subtotales y total del ajuste.'
            );
        }

        return [
            'recipient_snapshot' => $recipientSnapshot,
            'currency_code' => $currencyCode,
            'lines' => $normalizedLines,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function normalizeSnapshot(array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if (is_array($value)) {
                $snapshot[$key] = $this->normalizeSnapshot($value);
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar ajustes fiscales.'
            );
        }

        return $organizationId;
    }
}
