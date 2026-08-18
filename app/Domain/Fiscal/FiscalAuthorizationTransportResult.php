<?php
namespace App\Domain\Fiscal;
use App\Enums\FiscalAuthorizationOutcome;
final readonly class FiscalAuthorizationTransportResult { public function __construct(public FiscalAuthorizationOutcome $outcome,public ?string $resultCode=null){} }
