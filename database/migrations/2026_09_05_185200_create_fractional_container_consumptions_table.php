<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'fractional_container_consumptions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->foreignId('inventory_movement_line_id')
                    ->constrained('inventory_movement_lines')
                    ->restrictOnDelete();

                $table->foreignId('fractional_container_id')
                    ->constrained('fractional_containers')
                    ->restrictOnDelete();

                $table->unsignedInteger('sequence');
                $table->string('policy', 64);
                $table->decimal('consumed_base_quantity', 20, 6);
                $table->string('base_unit_code', 16);
                $table->string('state_before', 16);
                $table->string('state_after', 16);
                $table->decimal('remaining_before', 20, 6);
                $table->decimal('remaining_after', 20, 6);
                $table->timestamps();

                $table->unique(
                    [
                        'inventory_movement_line_id',
                        'sequence',
                    ],
                    'fractional_consumptions_line_sequence_unique'
                );

                $table->unique(
                    [
                        'inventory_movement_line_id',
                        'fractional_container_id',
                    ],
                    'fractional_consumptions_line_container_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'fractional_container_id',
                    ],
                    'fractional_consumptions_org_container_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'fractional_container_consumptions'
        );
    }
};
