<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();
            $table->foreignId('inventory_location_id');
            $table->string('condition', 32);
            $table->decimal('quantity', 20, 6)->default(0);
            $table->string('base_unit_code', 16);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(
                [
                    'organization_id',
                    'catalog_product_id',
                    'inventory_location_id',
                    'condition',
                ],
                'inv_balances_dimension_unique'
            );
            $table->index(
                ['organization_id', 'catalog_product_id'],
                'inv_balances_org_product_index'
            );

            $table->foreign(
                ['organization_id', 'inventory_location_id'],
                'inv_balances_location_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
