<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentRecipientEvidence extends Model
{
    use BelongsToOrganization;

    protected $table = 'fiscal_document_recipient_evidence';

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'document_type_code',
        'document_number',
        'vat_condition_code',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La evidencia fiscal del receptor es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La evidencia fiscal del receptor no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
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
