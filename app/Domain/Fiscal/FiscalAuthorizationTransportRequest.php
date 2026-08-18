<?php
namespace App\Domain\Fiscal;
final readonly class FiscalAuthorizationTransportRequest { public function __construct(public int $fiscalDocumentId,public int $fiscalPointOfSaleId,public int $assignedNumber){} }
