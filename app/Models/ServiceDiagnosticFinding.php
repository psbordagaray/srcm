<?php

namespace App\Models;

use App\Enums\ServiceFindingSeverity;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDiagnosticFinding extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_diagnostic_id',
        'position',
        'severity',
        'category',
        'description',
        'evidence_notes',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un hallazgo técnico es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un hallazgo técnico no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return ['severity' => ServiceFindingSeverity::class];
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(ServiceDiagnostic::class);
    }
}
