<?php

namespace App\Http\Requests\Concerns;

trait NormalizesOptionalWebsite
{
    protected function normalizeOptionalWebsite(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $website = trim($value);

        if ($website === '') {
            return null;
        }

        if (str_starts_with($website, '//')) {
            return 'https:'.$website;
        }

        if (! preg_match(
            '#^[a-z][a-z0-9+.-]*://#i',
            $website
        )) {
            return 'https://'.$website;
        }

        return $website;
    }
}
