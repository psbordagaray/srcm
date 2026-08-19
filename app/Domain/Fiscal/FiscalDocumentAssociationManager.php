<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAssociationEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentAssociationManager
{
    public const MODE_VOUCHERS = 'VOUCHERS';
    public const MODE_PERIOD = 'PERIOD';

    private const UNSUPPORTED_FCE_VOUCHER_CODES = [
        202, 203, 207, 208, 212, 213,
    ];

    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentAssociationData $data,
        User $actor
    ): FiscalDocumentAssociationEvidence {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId
        ): FiscalDocumentAssociationEvidence {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento fiscal no pertenece a la organización activa.'
                );
            }

            $this->assertAdjustmentDocument($document);
            $this->assertNotUnsupportedFce($document);

            $canonical = $this->canonicalize($data, $document);
            $fingerprint = $this->fingerprint($document, $canonical);

            $existing = FiscalDocumentAssociationEvidence::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new DomainException(
                        'El documento ya posee otra evidencia fiscal de asociación.'
                    );
                }

                $this->assertStoredEvidence($document, $existing);

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'La asociación fiscal debe quedar cerrada antes del primer intento de autorización.'
                );
            }

            return FiscalDocumentAssociationEvidence::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                'mode' => $canonical['mode'],
                'associated_vouchers' => $canonical['associated_vouchers'],
                'associated_voucher_count' => $canonical['associated_voucher_count'],
                'period_from_date' => $canonical['period_from_date'],
                'period_to_date' => $canonical['period_to_date'],
                'fingerprint' => $fingerprint,
                'recorded_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->id,
            ]);
        }, 3);
    }

    public function assertCompleteForAuthorization(
        FiscalDocument $document,
        int $organizationId
    ): FiscalDocumentAssociationEvidence {
        if ((int) $document->organization_id !== $organizationId) {
            throw new DomainException(
                'El documento fiscal no pertenece a la organización activa.'
            );
        }

        $this->assertAdjustmentDocument($document);
        $this->assertNotUnsupportedFce($document);

        $evidence = FiscalDocumentAssociationEvidence::query()
            ->forOrganization($organizationId)
            ->where('fiscal_document_id', $document->id)
            ->first();

        if (! $evidence) {
            throw new DomainException(
                'La nota de crédito/débito requiere evidencia fiscal explícita de asociación antes de autorizar.'
            );
        }

        $this->assertStoredEvidence($document, $evidence);

        return $evidence;
    }

    private function assertAdjustmentDocument(FiscalDocument $document): void
    {
        if (! in_array(
            $document->document_type,
            [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote],
            true
        )) {
            throw new DomainException(
                'La evidencia de asociación WSFE aplica únicamente a notas de crédito o débito.'
            );
        }
    }

    private function assertNotUnsupportedFce(FiscalDocument $document): void
    {
        $classification = $document->classification()->first();

        if (
            $classification
            && in_array(
                (int) $classification->voucher_code,
                self::UNSUPPORTED_FCE_VOUCHER_CODES,
                true
            )
        ) {
            throw new DomainException(
                'Las asociaciones de Factura de Crédito Electrónica permanecen fuera del alcance de este V1.'
            );
        }
    }

    /**
     * @return array{
     *   mode:string,
     *   associated_vouchers:?array,
     *   associated_voucher_count:int,
     *   period_from_date:?string,
     *   period_to_date:?string
     * }
     */
    private function canonicalize(
        FiscalDocumentAssociationData $data,
        FiscalDocument $document
    ): array {
        if ($data->mode === self::MODE_VOUCHERS) {
            return $this->canonicalizeVouchers($data);
        }

        if ($data->mode === self::MODE_PERIOD) {
            return $this->canonicalizePeriod($data, $document);
        }

        throw new DomainException(
            'El modo de asociación fiscal debe ser VOUCHERS o PERIOD.'
        );
    }

    /**
     * @return array{
     *   mode:string,
     *   associated_vouchers:list<array{
     *     voucher_type_code:int,
     *     point_of_sale_number:int,
     *     voucher_number:int,
     *     issuer_cuit:?string,
     *     voucher_date:?string
     *   }>,
     *   associated_voucher_count:int,
     *   period_from_date:null,
     *   period_to_date:null
     * }
     */
    private function canonicalizeVouchers(FiscalDocumentAssociationData $data): array
    {
        if ($data->periodFrom || $data->periodTo) {
            throw new DomainException(
                'VOUCHERS no puede mezclarse con PeriodoAsoc.'
            );
        }

        if ($data->vouchers === []) {
            throw new DomainException(
                'VOUCHERS exige al menos un comprobante asociado explícito.'
            );
        }

        $canonical = [];
        $identities = [];

        foreach ($data->vouchers as $voucher) {
            if (! $voucher instanceof FiscalAssociatedVoucherData) {
                throw new DomainException(
                    'Cada asociación debe ser FiscalAssociatedVoucherData.'
                );
            }

            if ($voucher->voucherTypeCode <= 0 || $voucher->voucherTypeCode >= 1000) {
                throw new DomainException(
                    'CbteAsoc.Tipo debe estar entre 1 y 999.'
                );
            }

            if ($voucher->pointOfSaleNumber <= 0 || $voucher->pointOfSaleNumber >= 99999) {
                throw new DomainException(
                    'CbteAsoc.PtoVta debe ser mayor que 0 y menor que 99999.'
                );
            }

            if ($voucher->voucherNumber <= 0 || $voucher->voucherNumber >= 99999999) {
                throw new DomainException(
                    'CbteAsoc.Nro debe ser mayor que 0 y menor que 99999999.'
                );
            }

            $issuerCuit = $voucher->issuerCuit === null
                ? null
                : trim($voucher->issuerCuit);

            if (
                $issuerCuit !== null
                && ! preg_match('/^[0-9]{11}$/', $issuerCuit)
            ) {
                throw new DomainException(
                    'CbteAsoc.Cuit, cuando se informa, debe contener exactamente 11 dígitos.'
                );
            }

            $identity = implode(':', [
                $voucher->voucherTypeCode,
                $voucher->pointOfSaleNumber,
                $voucher->voucherNumber,
            ]);

            if (isset($identities[$identity])) {
                throw new DomainException(
                    'No puede repetirse la misma identidad Tipo/PtoVta/Nro en CbtesAsoc.'
                );
            }

            $identities[$identity] = true;

            $canonical[] = [
                'voucher_type_code' => $voucher->voucherTypeCode,
                'point_of_sale_number' => $voucher->pointOfSaleNumber,
                'voucher_number' => $voucher->voucherNumber,
                'issuer_cuit' => $issuerCuit,
                'voucher_date' => $voucher->voucherDate?->toDateString(),
            ];
        }

        usort(
            $canonical,
            static fn (array $left, array $right): int => [
                $left['voucher_type_code'],
                $left['point_of_sale_number'],
                $left['voucher_number'],
            ] <=> [
                $right['voucher_type_code'],
                $right['point_of_sale_number'],
                $right['voucher_number'],
            ]
        );

        return [
            'mode' => self::MODE_VOUCHERS,
            'associated_vouchers' => $canonical,
            'associated_voucher_count' => count($canonical),
            'period_from_date' => null,
            'period_to_date' => null,
        ];
    }

    /**
     * @return array{
     *   mode:string,
     *   associated_vouchers:null,
     *   associated_voucher_count:int,
     *   period_from_date:string,
     *   period_to_date:string
     * }
     */
    private function canonicalizePeriod(
        FiscalDocumentAssociationData $data,
        FiscalDocument $document
    ): array {
        if ($data->vouchers !== []) {
            throw new DomainException(
                'PERIOD no puede mezclarse con CbtesAsoc.'
            );
        }

        if (! $data->periodFrom || ! $data->periodTo) {
            throw new DomainException(
                'PeriodoAsoc exige fecha desde y fecha hasta explícitas.'
            );
        }

        $from = $data->periodFrom->startOfDay();
        $to = $data->periodTo->startOfDay();

        if ($from->lessThanOrEqualTo(CarbonImmutable::parse('2006-01-01'))) {
            throw new DomainException(
                'PeriodoAsoc.FchDesde debe ser posterior a 2006-01-01.'
            );
        }

        if ($to->lessThan($from)) {
            throw new DomainException(
                'PeriodoAsoc.FchHasta no puede ser anterior a FchDesde.'
            );
        }

        $issueDate = $document->issueDateRecord()->first();

        if (! $issueDate) {
            throw new DomainException(
                'PeriodoAsoc requiere fecha fiscal explícita del comprobante.'
            );
        }

        if ($to->greaterThan($issueDate->issue_date->startOfDay())) {
            throw new DomainException(
                'PeriodoAsoc.FchHasta no puede superar CbteFch.'
            );
        }

        return [
            'mode' => self::MODE_PERIOD,
            'associated_vouchers' => null,
            'associated_voucher_count' => 0,
            'period_from_date' => $from->toDateString(),
            'period_to_date' => $to->toDateString(),
        ];
    }

    /**
     * @param array{
     *   mode:string,
     *   associated_vouchers:?array,
     *   associated_voucher_count:int,
     *   period_from_date:?string,
     *   period_to_date:?string
     * } $canonical
     */
    private function fingerprint(FiscalDocument $document, array $canonical): string
    {
        return hash('sha256', json_encode([
            'fiscal_document_public_id' => $document->public_id,
            'association' => $canonical,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertStoredEvidence(
        FiscalDocument $document,
        FiscalDocumentAssociationEvidence $evidence
    ): void {
        $data = new FiscalDocumentAssociationData(
            $document->id,
            $evidence->mode,
            $evidence->mode === self::MODE_VOUCHERS
                ? $this->storedVoucherData($evidence)
                : [],
            $evidence->period_from_date,
            $evidence->period_to_date,
        );

        $canonical = $this->canonicalize($data, $document);

        if (
            $canonical['associated_voucher_count']
            !== $evidence->associated_voucher_count
        ) {
            throw new DomainException(
                'La evidencia fiscal de asociación almacenada es inconsistente.'
            );
        }

        $expectedFingerprint = $this->fingerprint($document, $canonical);

        if (! hash_equals($expectedFingerprint, $evidence->fingerprint)) {
            throw new DomainException(
                'El fingerprint de la evidencia fiscal de asociación es inválido.'
            );
        }
    }

    /**
     * @return list<FiscalAssociatedVoucherData>
     */
    private function storedVoucherData(
        FiscalDocumentAssociationEvidence $evidence
    ): array {
        if (! is_array($evidence->associated_vouchers)) {
            throw new DomainException(
                'La lista de CbtesAsoc almacenada es inválida.'
            );
        }

        return array_map(
            static function (array $voucher): FiscalAssociatedVoucherData {
                return new FiscalAssociatedVoucherData(
                    (int) ($voucher['voucher_type_code'] ?? 0),
                    (int) ($voucher['point_of_sale_number'] ?? 0),
                    (int) ($voucher['voucher_number'] ?? 0),
                    array_key_exists('issuer_cuit', $voucher)
                        && $voucher['issuer_cuit'] !== null
                            ? (string) $voucher['issuer_cuit']
                            : null,
                    array_key_exists('voucher_date', $voucher)
                        && $voucher['voucher_date'] !== null
                            ? CarbonImmutable::parse((string) $voucher['voucher_date'])
                            : null,
                );
            },
            $evidence->associated_vouchers
        );
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar evidencia fiscal de asociación.'
            );
        }

        return $organizationId;
    }
}
