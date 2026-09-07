<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transformations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->timestamp('effective_at');
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'inventory_transformations_org_idem_unique'
            );
            $table->index(
                ['organization_id', 'effective_at'],
                'inventory_transformations_org_effective_index'
            );
        });

        Schema::create(
            'inventory_transformation_lineages',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('inventory_transformation_id');
                $table->unsignedBigInteger('inventory_movement_line_id');
                $table->string('direction', 16);
                $table->string('output_role', 24)->nullable();
                $table->unsignedInteger('sequence');
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'itl_organization_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'inventory_transformation_id',
                    'itl_transformation_fk'
                )
                    ->references('id')
                    ->on('inventory_transformations')
                    ->restrictOnDelete();

                $table->foreign(
                    'inventory_movement_line_id',
                    'itl_movement_line_fk'
                )
                    ->references('id')
                    ->on('inventory_movement_lines')
                    ->restrictOnDelete();

                $table->unique(
                    'inventory_movement_line_id',
                    'itl_movement_line_unique'
                );
                $table->unique(
                    [
                        'inventory_transformation_id',
                        'direction',
                        'sequence',
                    ],
                    'itl_direction_sequence_unique'
                );
                $table->index(
                    ['organization_id', 'inventory_transformation_id'],
                    'itl_org_transformation_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transformation_lineages');
        Schema::dropIfExists('inventory_transformations');
    }
};
