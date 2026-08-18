<?php

namespace App\Models;

use App\Enums\CommerceSaleLineType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentLine extends Model
{
    use BelongsToOrganization;
    public $timestamps = false;
    protected $fillable = [
        'organization_id', 'fiscal_document_id', 'commerce_sale_line_id', 'position',
        'line_type', 'description', 'quantity', 'unit_price_minor', 'line_total_minor',
        'created_at',
    ];
    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Una línea fiscal es inmutable.'));
        static::deleting(fn () => throw new DomainException('Una línea fiscal no puede eliminarse.'));
    }
    protected function casts(): array
    {
        return ['line_type' => CommerceSaleLineType::class, 'quantity' => 'decimal:6', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }
    public function document(): BelongsTo { return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id'); }
    public function commerceSaleLine(): BelongsTo { return $this->belongsTo(CommerceSaleLine::class); }
}
