<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePartPurchaseLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_part_purchase_id',
        'service_part_requirement_id',
        'sequence',
        'quantity',
        'unit_cost_minor',
        'line_total_minor',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'La línea de compra afectada es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'La línea de compra afectada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(
            ServicePartPurchase::class,
            'service_part_purchase_id'
        );
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(
            ServicePartRequirement::class,
            'service_part_requirement_id'
        );
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ServicePartConsumption::class);
    }
}
