<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('type', 32);
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('confirmed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('effective_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('source_reference')->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignId('reverses_movement_id')->nullable();
            $table->foreignId('replaces_movement_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'inv_movements_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'inv_movements_org_idempotency_unique'
            );
            $table->unique(
                ['organization_id', 'reverses_movement_id'],
                'inv_movements_org_reversal_unique'
            );
            $table->unique(
                ['organization_id', 'replaces_movement_id'],
                'inv_movements_org_replacement_unique'
            );
            $table->index(
                ['organization_id', 'status', 'effective_at'],
                'inv_movements_org_status_effective_index'
            );

            $table->foreign(
                ['organization_id', 'reverses_movement_id'],
                'inv_movements_reversal_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_movements')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'replaces_movement_id'],
                'inv_movements_replacement_org_fk'
            )->references(['organization_id', 'id'])
                ->on('inventory_movements')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
