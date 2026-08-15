<?php

namespace App\Domain\Finance;

use App\Models\FinancialAccount;
use App\Models\User;
use DomainException;

final class FinancialStatementXlsxPreviewer
{
    private const MAX_BYTES = 2097152;

    public function __construct(
        private readonly FinancialStatementXlsxReader $reader,
        private readonly FinancialStatementCsvPreviewer $csvPreviewer
    ) {
    }

    public function preview(
        FinancialAccount $account,
        string $path,
        string $originalName,
        User $actor,
        ?FinancialStatementCsvMapping $mapping = null
    ): FinancialStatementImportPreview {
        if (
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            ) !== 'xlsx'
        ) {
            throw new DomainException(
                'P7.4 requiere un archivo XLSX.'
            );
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException(
                'El archivo XLSX no está disponible para lectura.'
            );
        }

        $size = filesize($path);

        if (
            $size === false
            || $size <= 0
            || $size > self::MAX_BYTES
        ) {
            throw new DomainException(
                'El archivo XLSX debe tener contenido y no superar 2 MiB.'
            );
        }

        $fileSha = hash_file(
            'sha256',
            $path
        );

        if (
            ! is_string($fileSha)
            || $fileSha === ''
        ) {
            throw new DomainException(
                'No se pudo calcular la identidad del archivo XLSX.'
            );
        }

        $mapping ??=
            FinancialStatementCsvMapping::canonical();

        $rows = $this->reader->rows(
            $path,
            $mapping
        );

        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'srcm-xlsx-'
        );

        if ($temporaryPath === false) {
            throw new DomainException(
                'No se pudo preparar la vista previa XLSX.'
            );
        }

        $handle = fopen(
            $temporaryPath,
            'wb'
        );

        if ($handle === false) {
            @unlink($temporaryPath);

            throw new DomainException(
                'No se pudo preparar la vista previa XLSX.'
            );
        }

        try {
            foreach ($rows as $row) {
                if (
                    fputcsv(
                        $handle,
                        $row,
                        $mapping->delimiter,
                        '"',
                        ''
                    ) === false
                ) {
                    throw new DomainException(
                        'No se pudo normalizar una fila XLSX.'
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        try {
            $preview =
                $this->csvPreviewer->preview(
                    $account,
                    $temporaryPath,
                    'normalized.csv',
                    $actor,
                    $mapping
                );
        } finally {
            @unlink($temporaryPath);
        }

        $normalizedRows = array_map(
            static fn (
                FinancialStatementImportPreviewRow $row
            ): FinancialStatementImportPreviewRow =>
                new FinancialStatementImportPreviewRow(
                    lineNumber: $row->lineNumber,
                    sourceKey:
                        'xlsx:'.$fileSha
                        .':'.$row->lineNumber,
                    fingerprint:
                        $row->fingerprint,
                    occurredAt:
                        $row->occurredAt,
                    direction:
                        $row->direction,
                    currencyCode:
                        $row->currencyCode,
                    grossAmountMinor:
                        $row->grossAmountMinor,
                    feeAmountMinor:
                        $row->feeAmountMinor,
                    withholdingAmountMinor:
                        $row->withholdingAmountMinor,
                    netAmountMinor:
                        $row->netAmountMinor,
                    externalOperationId:
                        $row->externalOperationId,
                    reference:
                        $row->reference
                ),
            $preview->rows
        );

        return new FinancialStatementImportPreview(
            accountPublicId:
                $preview->accountPublicId,
            accountName:
                $preview->accountName,
            currencyCode:
                $preview->currencyCode,
            fileName:
                basename($originalName),
            fileSha256: $fileSha,
            rows: $normalizedRows
        );
    }
}
