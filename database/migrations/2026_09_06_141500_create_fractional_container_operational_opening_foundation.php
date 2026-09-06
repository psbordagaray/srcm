<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'fractional_container_opening_authorizations',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('catalog_product_id');
                $table->unsignedBigInteger('inventory_location_id');
                $table->string('condition', 32);
                $table->unsignedBigInteger('authorized_by_user_id');
                $table->timestampTz('valid_from');
                $table->timestampTz('valid_until');
                $table->unsignedInteger(
                    'max_concurrent_open_containers'
                );
                $table->unsignedInteger(
                    'max_new_openings'
                )->nullable();
                $table->unsignedInteger(
                    'target_ready_open_count'
                )->nullable();
                $table->string('idempotency_key', 120);
                $table->unsignedBigInteger(
                    'revoked_by_user_id'
                )->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->string(
                    'revocation_reason',
                    500
                )->nullable();
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'fc_open_auth_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'catalog_product_id',
                    'fc_open_auth_product_fk'
                )
                    ->references('id')
                    ->on('catalog_products')
                    ->restrictOnDelete();

                $table->foreign(
                    'inventory_location_id',
                    'fc_open_auth_location_fk'
                )
                    ->references('id')
                    ->on('inventory_locations')
                    ->restrictOnDelete();

                $table->foreign(
                    'authorized_by_user_id',
                    'fc_open_auth_authorizer_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    'revoked_by_user_id',
                    'fc_open_auth_revoker_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'fc_open_auth_org_key_uq'
                );

                $table->index(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'inventory_location_id',
                        'condition',
                        'valid_from',
                        'valid_until',
                    ],
                    'fc_open_auth_scope_window_idx'
                );
            }
        );

        Schema::create(
            'fractional_container_opening_authorization_containers',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger(
                    'opening_authorization_id'
                );
                $table->unsignedBigInteger(
                    'fractional_container_id'
                );
                $table->timestamps();

                $table->foreign(
                    'opening_authorization_id',
                    'fc_open_auth_container_auth_fk'
                )
                    ->references('id')
                    ->on(
                        'fractional_container_opening_authorizations'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'fractional_container_id',
                    'fc_open_auth_container_fc_fk'
                )
                    ->references('id')
                    ->on('fractional_containers')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'opening_authorization_id',
                        'fractional_container_id',
                    ],
                    'fc_open_auth_container_uq'
                );
            }
        );

        Schema::create(
            'fractional_container_opening_events',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger(
                    'opening_authorization_id'
                );
                $table->unsignedBigInteger(
                    'fractional_container_id'
                );
                $table->unsignedBigInteger(
                    'opened_by_user_id'
                );
                $table->string('idempotency_key', 64);
                $table->string('state_before', 16);
                $table->string('state_after', 16);
                $table->decimal(
                    'remaining_before',
                    20,
                    6
                );
                $table->decimal(
                    'remaining_after',
                    20,
                    6
                );
                $table->timestampTz('opened_at');
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'fc_open_event_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'opening_authorization_id',
                    'fc_open_event_auth_fk'
                )
                    ->references('id')
                    ->on(
                        'fractional_container_opening_authorizations'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'fractional_container_id',
                    'fc_open_event_container_fk'
                )
                    ->references('id')
                    ->on('fractional_containers')
                    ->restrictOnDelete();

                $table->foreign(
                    'opened_by_user_id',
                    'fc_open_event_actor_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'fc_open_event_org_key_uq'
                );

                $table->unique(
                    'fractional_container_id',
                    'fc_open_event_container_uq'
                );

                $table->index(
                    [
                        'opening_authorization_id',
                        'opened_at',
                    ],
                    'fc_open_event_auth_time_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'fractional_container_opening_events'
        );
        Schema::dropIfExists(
            'fractional_container_opening_authorization_containers'
        );
        Schema::dropIfExists(
            'fractional_container_opening_authorizations'
        );
    }
};
