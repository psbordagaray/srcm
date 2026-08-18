<?php
namespace App\Domain\Fiscal;
interface FiscalAuthorizationTransport { public function authorize(FiscalAuthorizationTransportRequest $request): FiscalAuthorizationTransportResult; }
