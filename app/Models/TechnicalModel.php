<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalModel extends Model
{
    protected $fillable = [
        'brand_id',
        'product_category_id',
        'code',
        'name',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function knowledgeEntity(): BelongsTo
    {
        return $this->belongsTo(
            Entity::class,
            'knowledge_entity_id'
        );
    }

    public function knowledgeIdentifier(): BelongsTo
    {
        return $this->belongsTo(
            Identifier::class,
            'knowledge_identifier_id'
        );
    }
}
