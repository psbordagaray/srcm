<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'fractional_containers',
            function (Blueprint $table): void {
                $table->foreignId(
                    'received_inventory_movement_line_id'
                )
                    ->nullable()
                    ->after('inventory_location_id')
                    ->constrained('inventory_movement_lines')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'organization_id',
                        'received_inventory_movement_line_id',
                    ],
                    'fractional_containers_receipt_line_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'fractional_containers',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'fractional_containers_receipt_line_idx'
                );
                $table->dropForeign([
                    'received_inventory_movement_line_id',
                ]);
                $table->dropColumn(
                    'received_inventory_movement_line_id'
                );
            }
        );
    }
};
