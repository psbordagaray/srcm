<?php

namespace App\Domain\Finance;

final readonly class FinancialStatementImportPreview
{
    /**
     * @param list<FinancialStatementImportPreviewRow> $rows
     */
    public function __construct(
        public string $accountPublicId,
        public string $accountName,
        public string $currencyCode,
        public string $fileName,
        public string $fileSha256,
        public array $rows
    ) {
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
