<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentIssueDate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'issue_date',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La fecha fiscal del comprobante es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La fecha fiscal del comprobante no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
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
