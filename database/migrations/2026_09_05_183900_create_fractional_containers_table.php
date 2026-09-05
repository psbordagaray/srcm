<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'fractional_containers',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();

                $table->foreignId('product_presentation_id')
                    ->nullable()
                    ->constrained('product_presentations')
                    ->restrictOnDelete();

                $table->foreignId('inventory_location_id')
                    ->constrained('inventory_locations')
                    ->restrictOnDelete();

                $table->string('container_code', 80);
                $table->string('normalized_container_code', 80);
                $table->string('condition', 32);
                $table->string('state', 16)->default('sealed');
                $table->decimal('original_base_quantity', 20, 6);
                $table->decimal('remaining_base_quantity', 20, 6);
                $table->string('base_unit_code', 16);
                $table->unsignedTinyInteger('base_quantity_scale');
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'normalized_container_code',
                    ],
                    'fractional_containers_org_code_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'inventory_location_id',
                        'state',
                    ],
                    'fractional_containers_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fractional_containers');
    }
};
