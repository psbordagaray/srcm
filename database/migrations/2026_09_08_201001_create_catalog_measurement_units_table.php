<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_measurement_units',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger(
                    'measurement_dimension_id'
                );
                $table->string('key', 160)->unique();
                $table->string('name', 160);
                $table->string('symbol', 32)->nullable();
                $table->text('description')->nullable();
                $table->string('status', 32);
                $table->timestamps();

                $table->foreign(
                    'measurement_dimension_id',
                    'cmu_dimension_fk'
                )
                    ->references('id')
                    ->on('catalog_measurement_dimensions')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'measurement_dimension_id',
                        'status',
                    ],
                    'cmu_dimension_status_idx'
                );

                $table->index(
                    'status',
                    'catalog_measurement_units_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_measurement_units');
    }
};
