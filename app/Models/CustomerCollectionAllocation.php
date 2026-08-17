<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerCollectionAllocation extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'customer_collection_id',
        'customer_receivable_id',
        'sequence',
        'amount_minor',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerCollectionAllocation $allocation
        ): void {
            if (blank($allocation->public_id)) {
                $allocation->public_id = (string) Str::uuid();
            }
        });

        static::updating(fn () => throw new DomainException(
            'Una aplicación de cobranza es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una aplicación de cobranza no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(
            CustomerCollection::class,
            'customer_collection_id'
        );
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(
            CustomerReceivable::class,
            'customer_receivable_id'
        );
    }
}
