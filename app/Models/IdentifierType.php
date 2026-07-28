<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentifierType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_unique',
        'active',
    ];

    protected $casts = [
        'is_unique' => 'boolean',
        'active' => 'boolean',
    ];

    public function identifiers(): HasMany
    {
        return $this->hasMany(Identifier::class);
    }
}
