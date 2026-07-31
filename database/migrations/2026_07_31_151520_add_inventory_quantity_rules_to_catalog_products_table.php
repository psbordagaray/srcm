<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->string('base_unit_code', 16)
                ->default('unit');
            $table->unsignedTinyInteger('quantity_scale')
                ->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->dropColumn([
                'base_unit_code',
                'quantity_scale',
            ]);
        });
    }
};
