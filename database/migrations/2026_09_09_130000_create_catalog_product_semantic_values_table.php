<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_product_semantic_values',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('catalog_product_id');
                $table->unsignedBigInteger('attribute_binding_id');

                $table->text('value_text')->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->bigInteger('value_integer')->nullable();
                $table->decimal('value_decimal', 38, 18)->nullable();
                $table->date('value_date')->nullable();
                $table->dateTime('value_datetime', 6)->nullable();

                $table->timestamps();

                $table->foreign(
                    'catalog_product_id',
                    'cpsv_product_fk'
                )
                    ->references('id')
                    ->on('catalog_products')
                    ->restrictOnDelete();

                $table->foreign(
                    'attribute_binding_id',
                    'cpsv_binding_fk'
                )
                    ->references('id')
                    ->on('catalog_attribute_bindings')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'catalog_product_id',
                        'attribute_binding_id',
                    ],
                    'cpsv_product_binding_unique'
                );

                $table->index(
                    'attribute_binding_id',
                    'cpsv_binding_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'catalog_product_semantic_values'
        );
    }
};
