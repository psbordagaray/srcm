<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_product_schema_versions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('product_definition_id')
                    ->constrained('catalog_product_definitions')
                    ->restrictOnDelete();

                $table->unsignedInteger('version');
                $table->string('status', 32);
                $table->text('change_summary')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('deprecated_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                $table->timestamps();

                $table->unique(
                    [
                        'product_definition_id',
                        'version',
                    ],
                    'catalog_schema_versions_definition_version_unique'
                );

                $table->index(
                    [
                        'product_definition_id',
                        'status',
                    ],
                    'catalog_schema_versions_definition_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_schema_versions');
    }
};
