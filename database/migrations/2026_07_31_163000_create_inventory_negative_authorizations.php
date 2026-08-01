<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'inventory_negative_requests',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('inventory_movement_id');
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('status', 16)->default('pending');
                $table->text('reason');
                $table->char('movement_fingerprint', 64);
                $table->char('snapshot_fingerprint', 64);
                $table->char('request_fingerprint', 64);
                $table->timestampTz('requested_at');
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('approved_at')->nullable();
                $table->foreignId('rejected_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestampTz('invalidated_at')->nullable();
                $table->text('invalidation_reason')->nullable();
                $table->timestampTz('fulfilled_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'inv_negative_requests_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'request_fingerprint'],
                    'inv_negative_requests_fingerprint_unique'
                );
                $table->index(
                    ['organization_id', 'status', 'requested_at'],
                    'inv_negative_requests_status_index'
                );
                $table->foreign(
                    ['organization_id', 'inventory_movement_id'],
                    'inv_negative_requests_movement_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_movements')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'requested_by_user_id'],
                    'inv_negative_requests_requester_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'approved_by_user_id'],
                    'inv_negative_requests_approver_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'rejected_by_user_id'],
                    'inv_negative_requests_rejecter_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'inventory_negative_request_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->foreignId('inventory_negative_request_id');
                $table->unsignedInteger('sequence');
                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->foreignId('inventory_location_id');
                $table->string('condition', 32);
                $table->decimal('current_quantity', 20, 6);
                $table->decimal('requested_quantity', 20, 6);
                $table->decimal('projected_quantity', 20, 6);
                $table->decimal('current_deficit', 20, 6);
                $table->decimal('projected_deficit', 20, 6);
                $table->decimal('incremental_deficit', 20, 6);
                $table->string('base_unit_code', 16);
                $table->unsignedBigInteger('balance_version');
                $table->boolean('creates_negative');
                $table->timestamps();

                $table->unique(
                    ['inventory_negative_request_id', 'sequence'],
                    'inv_negative_request_lines_sequence_unique'
                );
                $table->unique(
                    [
                        'inventory_negative_request_id',
                        'catalog_product_id',
                        'inventory_location_id',
                        'condition',
                    ],
                    'inv_negative_request_lines_dimension_unique'
                );
                $table->foreign(
                    ['organization_id', 'inventory_negative_request_id'],
                    'inv_negative_request_lines_request_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_requests')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_location_id'],
                    'inv_negative_request_lines_location_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'inventory_negative_overrides',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('inventory_negative_request_id');
                $table->foreignId('inventory_movement_id');
                $table->foreignId('authorized_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('granted_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('status', 16)->default('active');
                $table->char('movement_fingerprint', 64);
                $table->char('snapshot_fingerprint', 64);
                $table->timestampTz('issued_at');
                $table->timestampTz('consumed_at')->nullable();
                $table->foreignId('revoked_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('revoked_at')->nullable();
                $table->text('revocation_reason')->nullable();
                $table->timestampTz('invalidated_at')->nullable();
                $table->text('invalidation_reason')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'inv_negative_overrides_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_negative_request_id'],
                    'inv_negative_overrides_request_unique'
                );
                $table->index(
                    ['organization_id', 'status', 'issued_at'],
                    'inv_negative_overrides_status_index'
                );
                $table->foreign(
                    ['organization_id', 'inventory_negative_request_id'],
                    'inv_negative_overrides_request_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_requests')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_movement_id'],
                    'inv_negative_overrides_movement_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_movements')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'authorized_user_id'],
                    'inv_negative_overrides_authorized_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'granted_by_user_id'],
                    'inv_negative_overrides_grantor_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'revoked_by_user_id'],
                    'inv_negative_overrides_revoker_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_negative_overrides');
        Schema::dropIfExists('inventory_negative_request_lines');
        Schema::dropIfExists('inventory_negative_requests');
    }
};
