<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('product_category_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('code');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(
                ['brand_id', 'product_category_id', 'code'],
                'technical_models_identity_unique'
            );

            $table->index('code');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_models');
    }
};