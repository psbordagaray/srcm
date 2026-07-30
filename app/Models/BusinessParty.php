<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class BusinessParty extends Model
{
    public const TYPE_PERSON = 'person';

    public const TYPE_ORGANIZATION = 'organization';

    protected $fillable = [
        'party_type',
        'name',
        'tax_id',
        'email',
        'phone',
        'website',
    ];

    public function setPartyTypeAttribute(string $value): void
    {
        $this->attributes['party_type'] = Str::of($value)
            ->trim()
            ->lower()
            ->toString();
    }

    public function setNameAttribute(string $value): void
    {
        $name = Str::of($value)
            ->squish()
            ->toString();

        $this->attributes['name'] = $name;
        $this->attributes['normalized_name'] =
            static::normalizeName($name);
    }

    public function setTaxIdAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['tax_id'] = null;
            $this->attributes['normalized_tax_id'] = null;

            return;
        }

        $taxId = Str::of((string) $value)
            ->squish()
            ->upper()
            ->toString();

        $this->attributes['tax_id'] = $taxId;
        $this->attributes['normalized_tax_id'] =
            static::normalizeTaxId($taxId);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = filled($value)
            ? Str::of((string) $value)
                ->trim()
                ->lower()
                ->toString()
            : null;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = filled($value)
            ? Str::of((string) $value)
                ->squish()
                ->toString()
            : null;
    }

    public function setWebsiteAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['website'] = null;

            return;
        }

        $website = trim((string) $value);

        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        $this->attributes['website'] = $website;
    }

    public static function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public static function normalizeTaxId(string $value): string
    {
        $ascii = Str::upper(Str::ascii(trim($value)));

        return preg_replace('/[^A-Z0-9]+/', '', $ascii) ?? '';
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }
}
