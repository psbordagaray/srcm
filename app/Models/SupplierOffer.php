<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierOffer extends Model
{
    public const AVAILABILITY_UNKNOWN = 'unknown';
    public const AVAILABILITY_AVAILABLE = 'available';
    public const AVAILABILITY_LIMITED = 'limited';
    public const AVAILABILITY_UNAVAILABLE = 'unavailable';
    public const AVAILABILITY_ON_REQUEST = 'on_request';

    protected $fillable = [
        'supplier_id',
        'catalog_product_id',
        'supplier_code',
        'published_description',
        'cost_amount',
        'currency',
        'availability_status',
        'source_url',
        'checked_at',
        'commercial_terms',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'cost_amount' => 'decimal:2',
            'checked_at' => 'date',
            'active' => 'boolean',
        ];
    }

    public function setSupplierCodeAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['supplier_code'] = null;
            $this->attributes['normalized_supplier_code'] = '';
            return;
        }

        $code = Str::of((string) $value)->squish()->upper()->toString();
        $this->attributes['supplier_code'] = $code;
        $this->attributes['normalized_supplier_code'] = static::normalizeCode($code);
    }

    public function setCurrencyAttribute(?string $value): void
    {
        $this->attributes['currency'] = filled($value)
            ? Str::of((string) $value)->trim()->upper()->toString()
            : null;
    }

    public function setSourceUrlAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['source_url'] = null;
            return;
        }

        $url = trim((string) $value);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $this->attributes['source_url'] = $url;
    }

    public static function normalizeCode(string $value): string
    {
        $ascii = Str::upper(Str::ascii(trim($value)));
        return preg_replace('/[^A-Z0-9]+/', '', $ascii) ?? '';
    }

    public static function availabilityOptions(): array
    {
        return [
            self::AVAILABILITY_UNKNOWN => 'Sin confirmar',
            self::AVAILABILITY_AVAILABLE => 'Disponible',
            self::AVAILABILITY_LIMITED => 'Disponibilidad limitada',
            self::AVAILABILITY_UNAVAILABLE => 'No disponible',
            self::AVAILABILITY_ON_REQUEST => 'A pedido',
        ];
    }

    public function availabilityLabel(): string
    {
        return static::availabilityOptions()[$this->availability_status]
            ?? 'Estado desconocido';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }
}
