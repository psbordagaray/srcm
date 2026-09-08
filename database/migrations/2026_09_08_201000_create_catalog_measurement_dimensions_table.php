<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_measurement_dimensions',
            function (Blueprint $table): void {
                $table->id();
                $table->string('key', 160)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('status', 32);
                $table->timestamps();

                $table->index(
                    'status',
                    'catalog_measurement_dimensions_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_measurement_dimensions');
    }
};
