<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'inventory_negative_incidents',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('inventory_movement_id');
                $table->foreignId('inventory_negative_request_id');
                $table->foreignId('inventory_negative_override_id');
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('granted_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('status', 20)->default('open');
                $table->text('reason');
                $table->timestampTz('opened_at');
                $table->timestampTz('regularized_at')->nullable();
                $table->foreignId('resolved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('resolved_at')->nullable();
                $table->text('resolution_reason')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'inv_negative_incidents_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_movement_id'],
                    'inv_negative_incidents_movement_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_negative_request_id'],
                    'inv_negative_incidents_request_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_negative_override_id'],
                    'inv_negative_incidents_override_unique'
                );
                $table->index(
                    ['organization_id', 'status', 'opened_at'],
                    'inv_negative_incidents_status_index'
                );

                $table->foreign(
                    ['organization_id', 'inventory_movement_id'],
                    'inv_negative_incidents_movement_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_movements')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_negative_request_id'],
                    'inv_negative_incidents_request_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_requests')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_negative_override_id'],
                    'inv_negative_incidents_override_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_overrides')
                    ->restrictOnDelete();

                foreach ([
                    'requested_by_user_id' => 'requester',
                    'granted_by_user_id' => 'grantor',
                    'resolved_by_user_id' => 'resolver',
                ] as $column => $name) {
                    $table->foreign(
                        ['organization_id', $column],
                        "inv_negative_incidents_{$name}_fk"
                    )->references(['organization_id', 'user_id'])
                        ->on('organization_memberships')
                        ->restrictOnDelete();
                }
            }
        );

        Schema::create(
            'inventory_negative_incident_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->foreignId('inventory_negative_incident_id');
                $table->unsignedInteger('sequence');
                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->foreignId('inventory_location_id');
                $table->string('condition', 32);
                $table->decimal('previous_quantity', 20, 6);
                $table->decimal('outgoing_quantity', 20, 6);
                $table->decimal('incoming_quantity', 20, 6);
                $table->decimal('net_quantity', 20, 6);
                $table->decimal('resulting_quantity', 20, 6);
                $table->decimal('previous_deficit', 20, 6);
                $table->decimal('resulting_deficit', 20, 6);
                $table->decimal('incremental_deficit', 20, 6);
                $table->decimal('pending_deficit', 20, 6);
                $table->string('base_unit_code', 16);
                $table->timestampTz('regularized_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['inventory_negative_incident_id', 'sequence'],
                    'inv_negative_incident_lines_sequence_unique'
                );
                $table->unique(
                    [
                        'inventory_negative_incident_id',
                        'catalog_product_id',
                        'inventory_location_id',
                        'condition',
                    ],
                    'inv_negative_incident_lines_dimension_unique'
                );
                $table->foreign(
                    ['organization_id', 'inventory_negative_incident_id'],
                    'inv_negative_incident_lines_incident_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_incidents')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_location_id'],
                    'inv_negative_incident_lines_location_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'inventory_negative_incident_status_histories',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->foreignId('inventory_negative_incident_id');
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->foreignId('changed_by_user_id');
                $table->text('reason');
                $table->timestampTz('changed_at');
                $table->timestamps();

                $table->index(
                    ['inventory_negative_incident_id', 'changed_at'],
                    'inv_negative_incident_history_timeline_index'
                );
                $table->foreign(
                    'organization_id',
                    'inv_neg_incident_hist_org_fk'
                )->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();
                $table->foreign(
                    'changed_by_user_id',
                    'inv_neg_incident_hist_user_fk'
                )->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_negative_incident_id'],
                    'inv_negative_incident_history_incident_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_incidents')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'changed_by_user_id'],
                    'inv_negative_incident_history_actor_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'inventory_negative_incident_status_histories'
        );
        Schema::dropIfExists('inventory_negative_incident_lines');
        Schema::dropIfExists('inventory_negative_incidents');
    }
};
