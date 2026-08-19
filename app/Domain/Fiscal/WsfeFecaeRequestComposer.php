<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalDocumentConcept;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalIntegrationMode;
use App\Enums\FiscalTaxWsfeBucket;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAssociationEvidence;
use Carbon\CarbonInterface;
use DomainException;

final class WsfeFecaeRequestComposer implements WsfeFecaeRequestComposerContract
{
    private const UNSUPPORTED_FCE_CODES = [
        201, 202, 203,
        206, 207, 208,
        211, 212, 213,
    ];

    public function __construct(
        private readonly FiscalTaxWsfeClassificationManager $taxClassification,
        private readonly FiscalDocumentAssociationManager $associationManager,
    ) {
    }

    public function compose(
        FiscalDocument $document,
        int $voucherNumber
    ): WsfeFecaeRequestData {
        $stored = FiscalDocument::query()
            ->whereKey($document->id)
            ->where(
                'organization_id',
                $document->organization_id
            )
            ->with([
                'pointOfSale',
                'classification',
                'conceptRecord',
                'recipientEvidence',
                'issueDateRecord',
                'monetarySummary',
                'currencyEvidence',
                'paymentDueDateRecord',
                'associationEvidence',
                'taxComponents.wsfeIdentity',
            ])
            ->first();

        if (! $stored) {
            throw new DomainException(
                'El documento fiscal no existe bajo la identidad de organización declarada.'
            );
        }

        if (
            $voucherNumber < 1
            || $voucherNumber > 99_999_999
        ) {
            throw new DomainException(
                'El CbteNro remoto candidato debe estar entre 1 y 99999999.'
            );
        }

        [$pointOfSaleNumber, $voucherTypeCode] =
            $this->wsfeHeaderIdentity($stored);

        if (
            in_array(
                $voucherTypeCode,
                self::UNSUPPORTED_FCE_CODES,
                true
            )
        ) {
            throw new DomainException(
                'Factura de Crédito Electrónica permanece fuera del alcance del compositor FECAE estándar.'
            );
        }

        $concept = $this->concept($stored);
        $recipient = $this->recipient($stored);
        $issueDate = $this->issueDate($stored);
        $summary = $this->summary($stored);
        $currency = $this->currency($stored);
        $tax = $this->tax($stored, $summary);
        $service = $this->serviceDates(
            $stored,
            $concept
        );
        $association = $this->association($stored);

        $header = new WsfeFecaeHeaderData(
            recordCount: 1,
            pointOfSaleNumber: $pointOfSaleNumber,
            voucherTypeCode: $voucherTypeCode,
        );

        $detail = new WsfeFecaeDetailData(
            conceptCode: $this->conceptCode($concept->concept),
            documentTypeCode: $recipient['document_type_code'],
            documentNumber: $recipient['document_number'],
            voucherFrom: $voucherNumber,
            voucherTo: $voucherNumber,
            voucherDate: $this->wsfeDate($issueDate->issue_date),
            totalAmount: $this->minorToDecimal(
                (int) $stored->total_minor
            ),
            nonTaxedAmount: $this->minorToDecimal(
                $summary['non_taxed_amount_minor']
            ),
            netTaxableAmount: $this->minorToDecimal(
                $summary['net_taxable_amount_minor']
            ),
            exemptAmount: $this->minorToDecimal(
                $summary['exempt_amount_minor']
            ),
            tributesAmount: $this->minorToDecimal(
                $summary['tributes_amount_minor']
            ),
            vatAmount: $this->minorToDecimal(
                $summary['vat_amount_minor']
            ),
            serviceFrom: $service['from'],
            serviceTo: $service['to'],
            paymentDueDate: $service['due'],
            currencyId: $currency['currency_id'],
            currencyQuotation: $currency['quotation'],
            sameCurrencySettlement: $currency['same_currency'],
            recipientVatConditionId:
                $recipient['vat_condition_code'],
            associatedVouchers:
                $association['vouchers'],
            associatedPeriod:
                $association['period'],
            tributes: $tax['tributes'],
            vat: $tax['vat'],
        );

        return new WsfeFecaeRequestData(
            $header,
            $detail
        );
    }

    /**
     * @return array{0:int,1:int}
     */
    private function wsfeHeaderIdentity(
        FiscalDocument $document
    ): array {
        $point = $document->pointOfSale;

        if (
            ! $point
            || ! $point->active
            || $point->integration_mode
                !== FiscalIntegrationMode::WsfeV1
            || (int) $point->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'El compositor FECAE requiere un punto de venta WSFEv1 activo del mismo tenant.'
            );
        }

        $pointNumber = (int) $point->point_number;

        if (
            $pointNumber < 1
            || $pointNumber > 99_998
        ) {
            throw new DomainException(
                'PtoVta WSFE debe estar entre 1 y 99998.'
            );
        }

        $rawCode = $document->classification?->voucher_code;

        if (
            $rawCode === null
            || preg_match(
                '/^[0-9]{1,3}$/D',
                trim((string) $rawCode)
            ) !== 1
        ) {
            throw new DomainException(
                'CbteTipo WSFE debe provenir de clasificación fiscal explícita.'
            );
        }

        $voucherType = (int) trim(
            (string) $rawCode
        );

        if ($voucherType < 1) {
            throw new DomainException(
                'CbteTipo WSFE debe ser positivo.'
            );
        }

        return [$pointNumber, $voucherType];
    }

    private function concept(
        FiscalDocument $document
    ): \App\Models\FiscalDocumentConcept {
        $concept = $document->conceptRecord;

        if (
            ! $concept
            || (int) $concept->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'Concepto WSFE requiere evidencia fiscal explícita.'
            );
        }

        if (
            ! $concept->concept
            instanceof FiscalDocumentConcept
        ) {
            throw new DomainException(
                'El concepto fiscal almacenado es inválido.'
            );
        }

        return $concept;
    }

    /**
     * @return array{
     *   document_type_code:int,
     *   document_number:string,
     *   vat_condition_code:int
     * }
     */
    private function recipient(
        FiscalDocument $document
    ): array {
        $evidence = $document->recipientEvidence;

        if (
            ! $evidence
            || (int) $evidence->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'DocTipo, DocNro y CondicionIVAReceptorId requieren evidencia fiscal explícita.'
            );
        }

        $docType = trim(
            (string) $evidence->document_type_code
        );

        $docNumber = trim(
            (string) $evidence->document_number
        );

        $vatCondition = trim(
            (string) $evidence->vat_condition_code
        );

        if (
            preg_match('/^[0-9]{1,2}$/D', $docType)
                !== 1
            || (int) $docType < 1
        ) {
            throw new DomainException(
                'DocTipo no cabe en el contrato WSFE estándar.'
            );
        }

        if (
            preg_match(
                '/^[0-9]{1,11}$/D',
                $docNumber
            ) !== 1
        ) {
            throw new DomainException(
                'DocNro no cabe en Long(11) de WSFE.'
            );
        }

        if (
            preg_match(
                '/^[0-9]{1,2}$/D',
                $vatCondition
            ) !== 1
            || (int) $vatCondition < 1
        ) {
            throw new DomainException(
                'CondicionIVAReceptorId no cabe en Int(2) de WSFE.'
            );
        }

        return [
            'document_type_code' => (int) $docType,
            'document_number' => $docNumber,
            'vat_condition_code' => (int) $vatCondition,
        ];
    }

    private function issueDate(
        FiscalDocument $document
    ): \App\Models\FiscalDocumentIssueDate {
        $issueDate = $document->issueDateRecord;

        if (
            ! $issueDate
            || (int) $issueDate->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'CbteFch requiere fecha fiscal explícita.'
            );
        }

        return $issueDate;
    }

    /**
     * @return array{
     *   non_taxed_amount_minor:int,
     *   net_taxable_amount_minor:int,
     *   exempt_amount_minor:int,
     *   tributes_amount_minor:int,
     *   vat_amount_minor:int
     * }
     */
    private function summary(
        FiscalDocument $document
    ): array {
        $summary = $document->monetarySummary;

        if (
            ! $summary
            || (int) $summary->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'FECAE requiere resumen monetario fiscal explícito.'
            );
        }

        $values = [
            'non_taxed_amount_minor' =>
                (int) $summary->non_taxed_amount_minor,
            'net_taxable_amount_minor' =>
                (int) $summary->net_taxable_amount_minor,
            'exempt_amount_minor' =>
                (int) $summary->exempt_amount_minor,
            'tributes_amount_minor' =>
                (int) $summary->tributes_amount_minor,
            'vat_amount_minor' =>
                (int) $summary->vat_amount_minor,
        ];

        foreach ($values as $value) {
            if ($value < 0) {
                throw new DomainException(
                    'Los importes FECAE no pueden ser negativos.'
                );
            }
        }

        if (
            array_sum($values)
            !== (int) $document->total_minor
        ) {
            throw new DomainException(
                'ImpTotal no coincide con el resumen monetario fiscal inmutable.'
            );
        }

        return $values;
    }

    /**
     * @return array{
     *   currency_id:string,
     *   quotation:string,
     *   same_currency:string
     * }
     */
    private function currency(
        FiscalDocument $document
    ): array {
        $currency = $document->currencyEvidence;

        if (
            ! $currency
            || (int) $currency->organization_id
                !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'MonId y MonCotiz requieren evidencia fiscal explícita.'
            );
        }

        $source = strtoupper(
            trim((string) $currency->source_currency_code)
        );

        $documentCurrency = strtoupper(
            trim((string) $document->currency_code)
        );

        $currencyId = strtoupper(
            trim((string) $currency->arca_currency_code)
        );

        $quotationMicros =
            (int) $currency->quotation_micros;

        if ($source !== $documentCurrency) {
            throw new DomainException(
                'La moneda fuente no coincide con el documento fiscal.'
            );
        }

        if (
            preg_match(
                '/^[A-Z0-9]{3}$/D',
                $currencyId
            ) !== 1
            || $quotationMicros <= 0
            || $quotationMicros > 9_999_999_999
        ) {
            throw new DomainException(
                'La evidencia MonId/MonCotiz no cabe en el contrato WSFE.'
            );
        }

        if (
            $currencyId === 'PES'
            && (
                $quotationMicros !== 1_000_000
                || (bool) $currency->same_currency_settlement
            )
        ) {
            throw new DomainException(
                'Para PES, MonCotiz debe ser 1 y CanMisMonExt no puede ser S.'
            );
        }

        return [
            'currency_id' => $currencyId,
            'quotation' =>
                $this->microsToDecimal(
                    $quotationMicros
                ),
            'same_currency' =>
                (bool) $currency->same_currency_settlement
                    ? 'S'
                    : 'N',
        ];
    }

    /**
     * @param array{
     *   tributes_amount_minor:int,
     *   vat_amount_minor:int
     * } $summary
     * @return array{
     *   tributes:list<array<string,mixed>>,
     *   vat:list<array<string,mixed>>
     * }
     */
    private function tax(
        FiscalDocument $document,
        array $summary
    ): array {
        $components = $this->taxClassification
            ->assertCompleteForRequestComposition(
                $document
            );

        $vatAmount = 0;
        $tributeAmount = 0;
        $vatGroups = [];
        $tributes = [];

        foreach ($components as $component) {
            $bucket = $component['bucket'];

            if ($bucket === FiscalTaxWsfeBucket::Iva) {
                $vatAmount +=
                    $component['tax_amount_minor'];

                $key = (string) $component['arca_id'];

                if (! isset($vatGroups[$key])) {
                    $vatGroups[$key] = [
                        'id' => $component['arca_id'],
                        'base' => 0,
                        'amount' => 0,
                        'rate' =>
                            $component['rate_basis_points'],
                    ];
                }

                if (
                    $vatGroups[$key]['rate']
                    !== $component['rate_basis_points']
                ) {
                    throw new DomainException(
                        'Un mismo Id de IVA no puede representar tasas internas distintas.'
                    );
                }

                $vatGroups[$key]['base'] +=
                    $component['taxable_base_minor'];

                $vatGroups[$key]['amount'] +=
                    $component['tax_amount_minor'];

                continue;
            }

            if (
                $bucket
                !== FiscalTaxWsfeBucket::Tributo
            ) {
                throw new DomainException(
                    'Bucket tributario WSFE desconocido.'
                );
            }

            $tributeAmount +=
                $component['tax_amount_minor'];

            $entry = [
                'Id' => $component['arca_id'],
                'BaseImp' => $this->minorToDecimal(
                    $component['taxable_base_minor']
                ),
                'Alic' => $this->basisPointsToPercent(
                    $component['rate_basis_points']
                ),
                'Importe' => $this->minorToDecimal(
                    $component['tax_amount_minor']
                ),
            ];

            if (
                $component['tribute_description']
                !== null
            ) {
                $entry = [
                    'Id' => $entry['Id'],
                    'Desc' =>
                        $component['tribute_description'],
                    'BaseImp' => $entry['BaseImp'],
                    'Alic' => $entry['Alic'],
                    'Importe' => $entry['Importe'],
                ];
            }

            $tributes[] = $entry;
        }

        if (
            $vatAmount
            !== $summary['vat_amount_minor']
            || $tributeAmount
                !== $summary['tributes_amount_minor']
        ) {
            throw new DomainException(
                'Iva/Tributos clasificados no coinciden con ImpIVA/ImpTrib.'
            );
        }

        ksort(
            $vatGroups,
            SORT_NUMERIC
        );

        $vat = [];

        foreach ($vatGroups as $group) {
            if (
                $summary['vat_amount_minor'] === 0
            ) {
                continue;
            }

            $vat[] = [
                'Id' => $group['id'],
                'BaseImp' => $this->minorToDecimal(
                    $group['base']
                ),
                'Importe' => $this->minorToDecimal(
                    $group['amount']
                ),
            ];
        }

        if (
            $summary['vat_amount_minor'] > 0
            && $vat === []
        ) {
            throw new DomainException(
                'ImpIVA positivo requiere AlicIva explícito.'
            );
        }

        if (
            $summary['tributes_amount_minor'] === 0
        ) {
            $tributes = [];
        } elseif ($tributes === []) {
            throw new DomainException(
                'ImpTrib positivo requiere Tributos explícitos.'
            );
        }

        return [
            'tributes' => $tributes,
            'vat' => $vat,
        ];
    }

    /**
     * @return array{
     *   from:?string,
     *   to:?string,
     *   due:?string
     * }
     */
    private function serviceDates(
        FiscalDocument $document,
        \App\Models\FiscalDocumentConcept $concept
    ): array {
        $requires =
            $concept->concept->requiresServicePeriod();

        $due = $document->paymentDueDateRecord;

        if (! $requires) {
            if (
                $concept->service_period_from
                || $concept->service_period_to
                || $due
            ) {
                throw new DomainException(
                    'Concepto Productos no admite fechas de servicio ni FchVtoPago en este corte.'
                );
            }

            return [
                'from' => null,
                'to' => null,
                'due' => null,
            ];
        }

        if (
            ! $concept->service_period_from
            || ! $concept->service_period_to
            || ! $due
        ) {
            throw new DomainException(
                'Conceptos con servicios requieren FchServDesde, FchServHasta y FchVtoPago explícitos.'
            );
        }

        if (
            (int) $due->organization_id
            !== (int) $document->organization_id
        ) {
            throw new DomainException(
                'FchVtoPago cruza frontera de organización.'
            );
        }

        return [
            'from' => $this->wsfeDate(
                $concept->service_period_from
            ),
            'to' => $this->wsfeDate(
                $concept->service_period_to
            ),
            'due' => $this->wsfeDate(
                $due->payment_due_date
            ),
        ];
    }

    /**
     * @return array{
     *   vouchers:list<array<string,mixed>>,
     *   period:?array{FchDesde:string,FchHasta:string}
     * }
     */
    private function association(
        FiscalDocument $document
    ): array {
        if (
            $document->document_type
            === FiscalDocumentType::Invoice
        ) {
            if ($document->associationEvidence) {
                throw new DomainException(
                    'Una factura estándar no debe transportar asociación de nota.'
                );
            }

            return [
                'vouchers' => [],
                'period' => null,
            ];
        }

        if (
            ! in_array(
                $document->document_type,
                [
                    FiscalDocumentType::CreditNote,
                    FiscalDocumentType::DebitNote,
                ],
                true
            )
        ) {
            throw new DomainException(
                'Tipo de documento fiscal no soportado por el compositor FECAE.'
            );
        }

        $evidence = $this->associationManager
            ->assertCompleteForAuthorization(
                $document,
                (int) $document->organization_id
            );

        return $this->associationFields(
            $evidence
        );
    }

    /**
     * @return array{
     *   vouchers:list<array<string,mixed>>,
     *   period:?array{FchDesde:string,FchHasta:string}
     * }
     */
    private function associationFields(
        FiscalDocumentAssociationEvidence $evidence
    ): array {
        if (
            $evidence->mode
            === FiscalDocumentAssociationManager::MODE_VOUCHERS
        ) {
            if (
                ! is_array(
                    $evidence->associated_vouchers
                )
                || $evidence->associated_vouchers === []
            ) {
                throw new DomainException(
                    'CbtesAsoc almacenado es inválido.'
                );
            }

            $vouchers = [];

            foreach (
                $evidence->associated_vouchers
                as $voucher
            ) {
                $entry = [
                    'Tipo' =>
                        (int) ($voucher['voucher_type_code'] ?? 0),
                    'PtoVta' =>
                        (int) ($voucher['point_of_sale_number'] ?? 0),
                    'Nro' =>
                        (int) ($voucher['voucher_number'] ?? 0),
                ];

                if (
                    array_key_exists(
                        'issuer_cuit',
                        $voucher
                    )
                    && $voucher['issuer_cuit']
                        !== null
                ) {
                    $entry['Cuit'] =
                        (string) $voucher['issuer_cuit'];
                }

                if (
                    array_key_exists(
                        'voucher_date',
                        $voucher
                    )
                    && $voucher['voucher_date']
                        !== null
                ) {
                    $entry['CbteFch'] =
                        $this->wsfeDate(
                            \Carbon\CarbonImmutable::parse(
                                (string) $voucher['voucher_date']
                            )
                        );
                }

                $vouchers[] = $entry;
            }

            return [
                'vouchers' => $vouchers,
                'period' => null,
            ];
        }

        if (
            $evidence->mode
            === FiscalDocumentAssociationManager::MODE_PERIOD
            && $evidence->period_from_date
            && $evidence->period_to_date
        ) {
            return [
                'vouchers' => [],
                'period' => [
                    'FchDesde' => $this->wsfeDate(
                        $evidence->period_from_date
                    ),
                    'FchHasta' => $this->wsfeDate(
                        $evidence->period_to_date
                    ),
                ],
            ];
        }

        throw new DomainException(
            'La evidencia de asociación no puede mapearse de forma inequívoca.'
        );
    }

    private function conceptCode(
        FiscalDocumentConcept $concept
    ): int {
        return match ($concept) {
            FiscalDocumentConcept::Products => 1,
            FiscalDocumentConcept::Services => 2,
            FiscalDocumentConcept::ProductsAndServices => 3,
        };
    }

    private function wsfeDate(
        CarbonInterface|string $date
    ): string {
        $carbon = $date instanceof CarbonInterface
            ? $date
            : \Carbon\CarbonImmutable::parse($date);

        return $carbon->format('Ymd');
    }

    private function minorToDecimal(int $minor): string
    {
        if ($minor < 0) {
            throw new DomainException(
                'Un importe WSFE no puede ser negativo.'
            );
        }

        return intdiv($minor, 100)
            . '.'
            . str_pad(
                (string) ($minor % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    private function microsToDecimal(int $micros): string
    {
        if ($micros <= 0) {
            throw new DomainException(
                'MonCotiz debe ser mayor a cero.'
            );
        }

        return intdiv($micros, 1_000_000)
            . '.'
            . str_pad(
                (string) ($micros % 1_000_000),
                6,
                '0',
                STR_PAD_LEFT
            );
    }

    private function basisPointsToPercent(
        int $basisPoints
    ): string {
        if ($basisPoints < 0) {
            throw new DomainException(
                'Alic no puede ser negativa.'
            );
        }

        return intdiv($basisPoints, 100)
            . '.'
            . str_pad(
                (string) ($basisPoints % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }
}
