<?php

namespace App\Models;

use App\Enums\FiscalDocumentConcept as FiscalDocumentConceptValue;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentConcept extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'fiscal_document_id', 'concept',
        'service_period_from', 'service_period_to', 'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('El concepto fiscal es inmutable.'));
        static::deleting(fn () => throw new DomainException('El concepto fiscal no puede eliminarse.'));
    }

    protected function casts(): array
    {
        return [
            'concept' => FiscalDocumentConceptValue::class,
            'service_period_from' => 'immutable_date',
            'service_period_to' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
