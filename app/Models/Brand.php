<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brand extends Model
{
    /**
     * Atributos asignables masivamente.
     */
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'website',
        'description',
        'active',
    ];

    /**
     * Genera automáticamente el slug al crear.
     */
    protected static function booted(): void
    {
        static::creating(function (Brand $brand) {

            if (! empty($brand->slug)) {
                return;
            }

            $baseSlug = Str::slug($brand->name);

            $slug = $baseSlug;

            $counter = 2;

            while (static::where('slug', $slug)->exists()) {

                $slug = "{$baseSlug}-{$counter}";

                $counter++;

            }

            $brand->slug = $slug;

        });
    }
}