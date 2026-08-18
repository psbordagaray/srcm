<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentCurrencyEvidence extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'source_currency_code',
        'arca_currency_code',
        'quotation_micros',
        'same_currency_settlement',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La evidencia fiscal de moneda es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La evidencia fiscal de moneda no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'quotation_micros' => 'integer',
            'same_currency_settlement' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
