<?php

namespace App\Domain\Fiscal;

final readonly class WsfeFecaeDetailData
{
    /**
     * @param list<array{
     *   Tipo:int,
     *   PtoVta:int,
     *   Nro:int,
     *   Cuit?:string,
     *   CbteFch?:string
     * }> $associatedVouchers
     * @param array{FchDesde:string,FchHasta:string}|null $associatedPeriod
     * @param list<array{
     *   Id:int,
     *   Desc?:string,
     *   BaseImp:string,
     *   Alic:string,
     *   Importe:string
     * }> $tributes
     * @param list<array{
     *   Id:int,
     *   BaseImp:string,
     *   Importe:string
     * }> $vat
     */
    public function __construct(
        public int $conceptCode,
        public int $documentTypeCode,
        public string $documentNumber,
        public int $voucherFrom,
        public int $voucherTo,
        public string $voucherDate,
        public string $totalAmount,
        public string $nonTaxedAmount,
        public string $netTaxableAmount,
        public string $exemptAmount,
        public string $tributesAmount,
        public string $vatAmount,
        public ?string $serviceFrom,
        public ?string $serviceTo,
        public ?string $paymentDueDate,
        public string $currencyId,
        public string $currencyQuotation,
        public string $sameCurrencySettlement,
        public int $recipientVatConditionId,
        public array $associatedVouchers = [],
        public ?array $associatedPeriod = null,
        public array $tributes = [],
        public array $vat = [],
    ) {
    }

    /**
     * Canonical WSFE field map. SOAP envelope/Auth serialization remains transport-owned.
     *
     * @return array<string,mixed>
     */
    public function toWsfeArray(): array
    {
        $fields = [
            'Concepto' => $this->conceptCode,
            'DocTipo' => $this->documentTypeCode,
            'DocNro' => $this->documentNumber,
            'CbteDesde' => $this->voucherFrom,
            'CbteHasta' => $this->voucherTo,
            'CbteFch' => $this->voucherDate,
            'ImpTotal' => $this->totalAmount,
            'ImpTotConc' => $this->nonTaxedAmount,
            'ImpNeto' => $this->netTaxableAmount,
            'ImpOpEx' => $this->exemptAmount,
            'ImpTrib' => $this->tributesAmount,
            'ImpIVA' => $this->vatAmount,
        ];

        if ($this->serviceFrom !== null) {
            $fields['FchServDesde'] = $this->serviceFrom;
        }

        if ($this->serviceTo !== null) {
            $fields['FchServHasta'] = $this->serviceTo;
        }

        if ($this->paymentDueDate !== null) {
            $fields['FchVtoPago'] = $this->paymentDueDate;
        }

        $fields['MonId'] = $this->currencyId;
        $fields['MonCotiz'] = $this->currencyQuotation;
        $fields['CanMisMonExt'] = $this->sameCurrencySettlement;
        $fields['CondicionIVAReceptorId'] = $this->recipientVatConditionId;

        if ($this->associatedVouchers !== []) {
            $fields['CbtesAsoc'] = [
                'CbteAsoc' => $this->associatedVouchers,
            ];
        }

        if ($this->tributes !== []) {
            $fields['Tributos'] = [
                'Tributo' => $this->tributes,
            ];
        }

        if ($this->vat !== []) {
            $fields['Iva'] = [
                'AlicIva' => $this->vat,
            ];
        }

        if ($this->associatedPeriod !== null) {
            $fields['PeriodoAsoc'] = $this->associatedPeriod;
        }

        return $fields;
    }
}
