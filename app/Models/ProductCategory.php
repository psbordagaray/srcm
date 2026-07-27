<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    /**
     * Campos asignables masivamente.
     */
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'description',
        'active',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Eventos del modelo.
     */
    protected static function booted(): void
    {
        static::creating(function (ProductCategory $category) {

            if (empty($category->slug)) {

                $baseSlug = Str::slug($category->name);
                $slug = $baseSlug;
                $counter = 2;

                while (self::where('slug', $slug)->exists()) {
                    $slug = "{$baseSlug}-{$counter}";
                    $counter++;
                }

                $category->slug = $slug;
            }
        });
    }
}