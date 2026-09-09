<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_product_schema_capability_declarations',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger(
                    'product_schema_version_id'
                );
                $table->unsignedBigInteger(
                    'semantic_capability_definition_id'
                );
                $table->string('activation_mode', 32);
                $table->boolean('default_enabled');
                $table->timestamps();

                $table->foreign(
                    'product_schema_version_id',
                    'cpscd_schema_fk'
                )
                    ->references('id')
                    ->on('catalog_product_schema_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    'semantic_capability_definition_id',
                    'cpscd_capability_fk'
                )
                    ->references('id')
                    ->on('catalog_semantic_capability_definitions')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'product_schema_version_id',
                        'semantic_capability_definition_id',
                    ],
                    'cpscd_schema_capability_unique'
                );

                $table->index(
                    'semantic_capability_definition_id',
                    'cpscd_capability_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'catalog_product_schema_capability_declarations'
        );
    }
};
