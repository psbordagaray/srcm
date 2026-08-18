<?php
namespace App\Domain\Fiscal;
final readonly class FiscalVoucherClassificationData {public function __construct(public int $fiscalDocumentId,public string $voucherClass,public ?string $voucherCode){}}
