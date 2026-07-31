<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('inventory_movement_id');
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('catalog_product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();
            $table->string('condition', 32);
            $table->foreignId('source_location_id')->nullable();
            $table->foreignId('destination_location_id')->nullable();
            $table->decimal('entered_quantity', 20, 6);
            $table->string('entered_unit_code', 16);
            $table->decimal('conversion_factor', 20, 8);
            $table->decimal('base_quantity', 20, 6);
            $table->string('base_unit_code', 16);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['inventory_movement_id', 'sequence'],
                'inv_movement_lines_sequence_unique'
            );
            $table->index(
                ['organization_id', 'catalog_product_id'],
                'inv_movement_lines_org_product_index'
            );

            $table->foreign(
                ['organization_id', 'inventory_movement_id'],
                'inv_movement_lines_movement_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_movements')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'source_location_id'],
                'inv_movement_lines_source_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_locations')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'destination_location_id'],
                'inv_movement_lines_destination_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_lines');
    }
};
