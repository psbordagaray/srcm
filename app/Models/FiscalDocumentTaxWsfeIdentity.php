<?php

namespace App\Models;

use App\Enums\FiscalTaxWsfeBucket;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentTaxWsfeIdentity extends Model
{
    use BelongsToOrganization;

    protected $table = 'fiscal_document_tax_wsfe_identities';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'fiscal_document_tax_id',
        'bucket',
        'arca_id',
        'tribute_description',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La identidad WSFE del componente tributario es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La identidad WSFE del componente tributario no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'bucket' => FiscalTaxWsfeBucket::class,
            'arca_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(
            FiscalDocument::class,
            'fiscal_document_id'
        );
    }

    public function taxComponent(): BelongsTo
    {
        return $this->belongsTo(
            FiscalDocumentTax::class,
            'fiscal_document_tax_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by_user_id'
        );
    }
}
