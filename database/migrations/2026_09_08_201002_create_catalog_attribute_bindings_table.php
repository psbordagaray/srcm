<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_attribute_bindings',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger(
                    'product_schema_version_id'
                );
                $table->unsignedBigInteger(
                    'attribute_definition_id'
                );
                $table->string('value_type', 32);
                $table->string('value_scope', 32);
                $table->unsignedBigInteger(
                    'measurement_unit_id'
                )->nullable();
                $table->timestamps();

                $table->foreign(
                    'product_schema_version_id',
                    'cab_schema_fk'
                )
                    ->references('id')
                    ->on('catalog_product_schema_versions')
                    ->restrictOnDelete();

                $table->foreign(
                    'attribute_definition_id',
                    'cab_attribute_fk'
                )
                    ->references('id')
                    ->on('catalog_attribute_definitions')
                    ->restrictOnDelete();

                $table->foreign(
                    'measurement_unit_id',
                    'cab_measurement_unit_fk'
                )
                    ->references('id')
                    ->on('catalog_measurement_units')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'product_schema_version_id',
                        'attribute_definition_id',
                    ],
                    'cab_schema_attribute_unique'
                );

                $table->index(
                    'attribute_definition_id',
                    'cab_attribute_idx'
                );

                $table->index(
                    [
                        'product_schema_version_id',
                        'value_scope',
                    ],
                    'cab_schema_scope_idx'
                );

                $table->index(
                    [
                        'product_schema_version_id',
                        'value_type',
                    ],
                    'cab_schema_type_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_attribute_bindings');
    }
};
