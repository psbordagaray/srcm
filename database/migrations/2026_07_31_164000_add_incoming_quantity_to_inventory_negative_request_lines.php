<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'inventory_negative_request_lines',
            function (Blueprint $table): void {
                $table->decimal('incoming_quantity', 20, 6)
                    ->default(0)
                    ->after('requested_quantity');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'inventory_negative_request_lines',
            function (Blueprint $table): void {
                $table->dropColumn('incoming_quantity');
            }
        );
    }
};
