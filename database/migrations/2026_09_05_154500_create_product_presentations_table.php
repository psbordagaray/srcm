<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'product_presentations',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();

                $table->string('unit_code', 16);
                $table->string('name', 120);
                $table->unsignedTinyInteger('quantity_scale')
                    ->default(0);
                $table->decimal('conversion_factor', 20, 8);
                $table->string('base_unit_code', 16);
                $table->unsignedTinyInteger('base_quantity_scale');
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'unit_code',
                    ],
                    'product_presentations_org_product_unit_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'active',
                    ],
                    'product_presentations_org_product_active_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_presentations');
    }
};
