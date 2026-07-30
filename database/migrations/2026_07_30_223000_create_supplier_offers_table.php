<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();
            $table->string('supplier_code', 120)->nullable();
            $table->string('normalized_supplier_code', 120)->default('');
            $table->text('published_description')->nullable();
            $table->decimal('cost_amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('availability_status', 24)->default('unknown');
            $table->string('source_url', 2048)->nullable();
            $table->date('checked_at');
            $table->text('commercial_terms')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(
                ['supplier_id', 'catalog_product_id', 'normalized_supplier_code'],
                'supplier_offers_identity_unique'
            );
            $table->index('normalized_supplier_code');
            $table->index('availability_status');
            $table->index('checked_at');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_offers');
    }
};
