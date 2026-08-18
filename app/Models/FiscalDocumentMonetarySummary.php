<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentMonetarySummary extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'non_taxed_amount_minor',
        'net_taxable_amount_minor',
        'exempt_amount_minor',
        'tributes_amount_minor',
        'vat_amount_minor',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'El resumen monetario fiscal es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'El resumen monetario fiscal no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'non_taxed_amount_minor' => 'integer',
            'net_taxable_amount_minor' => 'integer',
            'exempt_amount_minor' => 'integer',
            'tributes_amount_minor' => 'integer',
            'vat_amount_minor' => 'integer',
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
