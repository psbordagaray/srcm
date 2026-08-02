<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('asset_type', 40);
            $table->string('brand_name');
            $table->string('normalized_brand_name');
            $table->string('model_name');
            $table->string('normalized_model_name');
            $table->string('color', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'srv_assets_org_id_unique'
            );
            $table->index(
                [
                    'organization_id',
                    'asset_type',
                    'normalized_brand_name',
                    'normalized_model_name',
                ],
                'srv_assets_org_identity_index'
            );
        });

        Schema::create(
            'service_asset_identifiers',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_asset_id');
                $table->string('identifier_type', 40);
                $table->string('value');
                $table->string('normalized_value');
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'identifier_type',
                        'normalized_value',
                    ],
                    'srv_asset_ids_org_identity_unique'
                );
                $table->index(
                    ['organization_id', 'service_asset_id'],
                    'srv_asset_ids_org_asset_index'
                );
                $table->foreign(
                    ['organization_id', 'service_asset_id'],
                    'srv_asset_ids_asset_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_assets')
                    ->restrictOnDelete();
            }
        );

        Schema::create('service_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('order_number');
            $table->unsignedBigInteger('service_asset_id');
            $table->unsignedBigInteger('customer_business_party_id')
                ->nullable();
            $table->unsignedBigInteger('owner_business_party_id')
                ->nullable();
            $table->unsignedBigInteger('intake_location_id');
            $table->string('status', 40)->default('received');
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->timestampTz('promised_at')->nullable();
            $table->string('idempotency_key', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'srv_orders_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'order_number'],
                'srv_orders_org_number_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'srv_orders_org_idempotency_unique'
            );
            $table->index(
                ['organization_id', 'status', 'received_at'],
                'srv_orders_org_status_received_index'
            );
            $table->foreign(
                ['organization_id', 'service_asset_id'],
                'srv_orders_asset_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_assets')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'customer_business_party_id'],
                'srv_orders_customer_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('business_parties')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'owner_business_party_id'],
                'srv_orders_owner_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('business_parties')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'intake_location_id'],
                'srv_orders_location_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('inventory_locations')
                ->restrictOnDelete();
        });

        Schema::create(
            'service_order_intakes',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->string('asset_type_snapshot', 40);
                $table->string('brand_name_snapshot');
                $table->string('model_name_snapshot');
                $table->string('color_snapshot', 100)->nullable();
                $table->json('identifiers_snapshot');
                $table->string('customer_name_snapshot');
                $table->string('owner_name_snapshot');
                $table->text('customer_reported_issue');
                $table->text('intake_observations')->nullable();
                $table->text('received_accessories')->nullable();
                $table->boolean('contact_available')->default(false);
                $table->string('contact_reference')->nullable();
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('recorded_at');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_order_id'],
                    'srv_intakes_org_order_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_intakes_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_order_status_histories',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->foreignId('changed_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->text('reason');
                $table->timestampTz('changed_at');
                $table->timestamps();

                $table->index(
                    ['organization_id', 'service_order_id', 'changed_at'],
                    'srv_status_history_order_index'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_status_history_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_custody_events',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->string('event_type', 40);
                $table->string('from_holder_type', 40);
                $table->string('from_holder_reference')->nullable();
                $table->string('from_holder_name');
                $table->string('to_holder_type', 40);
                $table->string('to_holder_reference')->nullable();
                $table->string('to_holder_name');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->text('condition_notes')->nullable();
                $table->text('accessories_snapshot')->nullable();
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('occurred_at');
                $table->timestamps();

                $table->index(
                    ['organization_id', 'service_order_id', 'occurred_at'],
                    'srv_custody_order_index'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_custody_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'location_id'],
                    'srv_custody_location_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_custody_events');
        Schema::dropIfExists('service_order_status_histories');
        Schema::dropIfExists('service_order_intakes');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('service_asset_identifiers');
        Schema::dropIfExists('service_assets');
    }
};
