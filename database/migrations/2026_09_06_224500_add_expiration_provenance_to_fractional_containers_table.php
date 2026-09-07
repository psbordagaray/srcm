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
                $table->date('expires_on')
                    ->nullable()
                    ->after('received_inventory_movement_line_id');

                $table->foreignId('expiration_receipt_line_id')
                    ->nullable()
                    ->after('expires_on')
                    ->constrained('inventory_movement_lines')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'organization_id',
                        'expiration_receipt_line_id',
                    ],
                    'fractional_containers_expiration_receipt_idx'
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
                    'fractional_containers_expiration_receipt_idx'
                );
                $table->dropForeign([
                    'expiration_receipt_line_id',
                ]);
                $table->dropColumn([
                    'expires_on',
                    'expiration_receipt_line_id',
                ]);
            }
        );
    }
};
