<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'ALTER TABLE catalog_products '
                .'ADD COLUMN product_definition_id INTEGER NULL'
            );

            DB::statement(
                'CREATE INDEX catalog_products_product_definition_id_index '
                .'ON catalog_products (product_definition_id)'
            );

            return;
        }

        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->foreignId('product_definition_id')
                ->nullable()
                ->constrained('catalog_product_definitions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS '
                .'catalog_products_product_definition_id_index'
            );

            DB::statement(
                'ALTER TABLE catalog_products '
                .'DROP COLUMN product_definition_id'
            );

            return;
        }

        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_definition_id');
        });
    }
};
