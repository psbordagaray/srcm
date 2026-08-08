<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationProductPrice extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'currency_code',
        'amount_minor',
        'valid_from',
        'valid_until',
        'is_current',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'is_current' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }
}
