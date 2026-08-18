<?php
namespace App\Domain\Fiscal;
interface FiscalAuthorizationCredentialStore { public function configuredFor(int $organizationId): bool; }
