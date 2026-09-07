<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'inventory_reservations',
            function (Blueprint $table): void {
                $table->decimal(
                    'minimum_fulfillment_quantity',
                    18,
                    6
                )->nullable()->after('quantity');

                $table->decimal(
                    'maximum_fulfillment_quantity',
                    18,
                    6
                )->nullable()->after(
                    'minimum_fulfillment_quantity'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'inventory_reservations',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'minimum_fulfillment_quantity',
                    'maximum_fulfillment_quantity',
                ]);
            }
        );
    }
};
