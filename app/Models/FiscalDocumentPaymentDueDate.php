<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentPaymentDueDate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'fiscal_document_id',
        'payment_due_date',
        'recorded_at',
        'recorded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La fecha fiscal de vencimiento de pago es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La fecha fiscal de vencimiento de pago no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'payment_due_date' => 'immutable_date',
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
