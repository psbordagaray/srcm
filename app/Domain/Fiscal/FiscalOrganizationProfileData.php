<?php

namespace App\Domain\Fiscal;

final readonly class FiscalOrganizationProfileData
{
    public function __construct(
        public string $legalName,
        public string $taxId,
        public string $vatConditionCode,
        public ?string $grossIncomeNumber,
        public string $activityStartedOn,
        public string $addressLine,
        public string $city,
        public string $provinceCode,
        public string $postalCode,
        public string $countryCode = 'AR'
    ) {
    }
}

