<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'service_custody_events',
            function (Blueprint $table): void {
                $table->unique(
                    ['organization_id', 'id'],
                    'srv_custody_org_id_unique'
                );
            }
        );

        Schema::create('service_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->unsignedBigInteger('service_order_id');
            $table->unsignedBigInteger('service_quote_option_id');
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->text('description');
            $table->string('execution_mode', 30);
            $table->unsignedBigInteger('provider_business_party_id')
                ->nullable();
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('status', 30)->default('planned');
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('planned_at');
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'srv_work_items_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'service_order_id', 'sequence'],
                'srv_work_items_order_sequence_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'srv_work_items_org_idempotency_unique'
            );
            $table->index(
                ['organization_id', 'service_order_id', 'status'],
                'srv_work_items_order_status_index'
            );
            $table->foreign(
                ['organization_id', 'service_order_id'],
                'srv_work_items_order_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_orders')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_quote_option_id'],
                'srv_work_items_option_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_quote_options')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'provider_business_party_id'],
                'srv_work_items_provider_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('business_parties')
                ->restrictOnDelete();
        });

        Schema::create(
            'service_work_status_histories',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_work_item_id');
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->foreignId('changed_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->text('reason');
                $table->timestampTz('changed_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->index(
                    [
                        'organization_id',
                        'service_work_item_id',
                        'changed_at',
                    ],
                    'srv_work_history_item_index'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_work_history_idempotency_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_work_item_id'],
                    'srv_work_history_item_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_work_items')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_work_custody_links',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_work_item_id');
                $table->unsignedBigInteger('service_custody_event_id');
                $table->string('direction', 30);
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_work_custody_event_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_work_custody_idempotency_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_work_item_id'],
                    'srv_work_custody_item_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_work_items')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_work_custody_event_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_custody_events')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_work_reports',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_work_item_id');
                $table->string('outcome', 30);
                $table->text('result_summary');
                $table->text('work_performed');
                $table->text('unresolved_reason')->nullable();
                $table->unsignedInteger('warranty_days')->nullable();
                $table->text('warranty_terms')->nullable();
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('recorded_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_work_item_id'],
                    'srv_work_reports_item_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_work_reports_idempotency_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_work_item_id'],
                    'srv_work_reports_item_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_work_items')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_work_reports');
        Schema::dropIfExists('service_work_custody_links');
        Schema::dropIfExists('service_work_status_histories');
        Schema::dropIfExists('service_work_items');

        Schema::table(
            'service_custody_events',
            function (Blueprint $table): void {
                $table->dropUnique('srv_custody_org_id_unique');
            }
        );
    }
};
