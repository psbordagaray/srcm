<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentAssociationEvidence extends Model
{
    use BelongsToOrganization;

    protected $table = 'fiscal_document_association_evidence';

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'mode',
        'associated_vouchers',
        'associated_voucher_count',
        'period_from_date',
        'period_to_date',
        'fingerprint',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La evidencia fiscal de asociación es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La evidencia fiscal de asociación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'associated_vouchers' => 'array',
            'associated_voucher_count' => 'integer',
            'period_from_date' => 'immutable_date',
            'period_to_date' => 'immutable_date',
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
