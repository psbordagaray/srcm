<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_category_id')
                ->constrained('product_categories')
                ->restrictOnDelete();

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->restrictOnDelete();

            $table->foreignId('manufacturer_id')
                ->nullable()
                ->constrained('manufacturers')
                ->restrictOnDelete();

            $table->string('sku');
            $table->string('normalized_sku')->unique();
            $table->string('name');
            $table->string('normalized_name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);

            $table->foreignId('knowledge_entity_id')
                ->nullable()
                ->unique()
                ->constrained('entities')
                ->restrictOnDelete();

            $table->foreignId('knowledge_identifier_id')
                ->nullable()
                ->unique()
                ->constrained('identifiers')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('sku');
            $table->index('name');
            $table->index('normalized_name');
            $table->index('active');
            $table->index([
                'product_category_id',
                'brand_id',
                'normalized_name',
            ], 'catalog_products_probable_identity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
