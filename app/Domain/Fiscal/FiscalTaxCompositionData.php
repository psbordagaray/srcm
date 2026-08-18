<?php
namespace App\Domain\Fiscal;
final readonly class FiscalTaxCompositionData {public function __construct(public int $fiscalDocumentId,public array $components,public string $idempotencyKey){}}
